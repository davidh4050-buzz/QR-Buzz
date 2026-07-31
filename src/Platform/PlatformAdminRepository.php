<?php
namespace QRBuzz\Platform;

use QRBuzz\Analytics\UserAgentParser;
use QRBuzz\Database\Schema;
use QRBuzz\Models\QRCode;

class PlatformAdminRepository {

    private UserAgentParser $parser;

    public function __construct(?UserAgentParser $parser = null) { $this->parser = $parser ?: new UserAgentParser(); }

    public function summary(): array {
        global $wpdb;
        $since24 = gmdate('Y-m-d H:i:s', strtotime('-24 hours', current_time('timestamp')));
        $since7 = gmdate('Y-m-d H:i:s', strtotime('-7 days', current_time('timestamp')));
        return [
            'users' => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users}"),
            'workspaces' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::workspacesTable()),
            'active_workspaces' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::workspacesTable() . " WHERE status = 'active'"),
            'qrs' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::qrcodesTable()),
            'campaigns' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::campaignsTable() . " WHERE status = 'active'"),
            'scans' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::scansTable()),
            'scans_24h' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::scansTable() . ' WHERE scanned_at >= %s', $since24)),
            'scans_7d' => (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::scansTable() . ' WHERE scanned_at >= %s', $since7)),
            'paid_subscriptions' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::subscriptionsTable() . " WHERE plan_key <> 'free' AND status IN ('active','trialing')"),
            'failed_webhooks' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::webhookLogsTable() . " WHERE status = 'failed'"),
            'unresolved_errors' => (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::applicationErrorsTable() . " WHERE status IN ('new','investigating')"),
        ];
    }

    public function attention(): array {
        $summary = $this->summary();
        $items = [];
        if ($summary['failed_webhooks'] > 0) { $items[] = ['Failed webhooks', $summary['failed_webhooks'] . ' Stripe webhook events need review.', '/platform-admin/webhooks?status=failed']; }
        if ($summary['unresolved_errors'] > 0) { $items[] = ['Unresolved errors', $summary['unresolved_errors'] . ' application errors are open.', '/platform-admin/errors']; }
        if (!$this->webhookSecretConfigured()) { $items[] = ['Webhook secret missing', 'Stripe webhook signature verification is not configured.', '/platform-admin/system-health']; }
        if (defined('WP_DEBUG') && WP_DEBUG && $this->environmentLabel() === 'production') { $items[] = ['Debug mode in production', 'WP_DEBUG appears enabled while the environment label is production.', '/platform-admin/system-health']; }
        return $items;
    }

    public function recentActivity(int $limit = 12): array {
        global $wpdb;
        $events = $wpdb->get_results($wpdb->prepare('SELECT event_name AS title, occurred_at AS happened_at, object_type, object_id FROM ' . Schema::platformEventsTable() . ' ORDER BY occurred_at DESC LIMIT %d', $limit)) ?: [];
        if ($events) { return $events; }
        return $wpdb->get_results($wpdb->prepare('(SELECT CONCAT("QR created: ", name) AS title, created_at AS happened_at, "qr" AS object_type, id AS object_id FROM ' . Schema::qrcodesTable() . ') UNION ALL (SELECT CONCAT("Campaign created: ", name), created_at, "campaign", id FROM ' . Schema::campaignsTable() . ') ORDER BY happened_at DESC LIMIT %d', $limit)) ?: [];
    }

    public function users(string $search = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        [$where, $params] = $this->userWhere($search);
        $params[] = $perPage; $params[] = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results($wpdb->prepare("SELECT u.ID, u.user_email, u.display_name, u.user_registered, p.email_verified, p.onboarding_status, p.last_login_at, w.id AS workspace_id, w.name AS workspace_name, w.plan_key, s.status AS subscription_status FROM {$wpdb->users} u LEFT JOIN " . Schema::profilesTable() . ' p ON p.user_id = u.ID LEFT JOIN ' . Schema::membershipsTable() . ' m ON m.user_id = u.ID LEFT JOIN ' . Schema::workspacesTable() . ' w ON w.id = m.workspace_id LEFT JOIN ' . Schema::subscriptionsTable() . ' s ON s.workspace_id = w.id ' . $where . ' GROUP BY u.ID ORDER BY u.user_registered DESC LIMIT %d OFFSET %d', ...$params)) ?: [];
    }

    public function userCount(string $search = ''): int {
        global $wpdb;
        [$where, $params] = $this->userWhere($search);
        return (int) ($params ? $wpdb->get_var($wpdb->prepare("SELECT COUNT(DISTINCT u.ID) FROM {$wpdb->users} u LEFT JOIN " . Schema::membershipsTable() . ' m ON m.user_id = u.ID LEFT JOIN ' . Schema::workspacesTable() . ' w ON w.id = m.workspace_id ' . $where, ...$params)) : $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->users} u " . $where));
    }

    public function userDetail(int $userId): ?object {
        global $wpdb;
        return $wpdb->get_row($wpdb->prepare("SELECT u.*, p.email_verified, p.onboarding_status, p.onboarding_step, p.last_login_at, p.created_at AS profile_created_at FROM {$wpdb->users} u LEFT JOIN " . Schema::profilesTable() . ' p ON p.user_id = u.ID WHERE u.ID = %d LIMIT 1', $userId));
    }

    public function workspaces(string $search = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        [$where, $params] = $this->workspaceWhere($search);
        $params[] = $perPage; $params[] = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results($wpdb->prepare("SELECT w.*, u.display_name AS owner_name, u.user_email AS owner_email, s.status AS subscription_status, COUNT(DISTINCT q.id) AS qr_count, COUNT(DISTINCT c.id) AS campaign_count, COUNT(sc.id) AS scan_count, MAX(GREATEST(COALESCE(q.updated_at, w.updated_at), COALESCE(c.updated_at, w.updated_at), COALESCE(sc.scanned_at, w.updated_at))) AS last_activity FROM " . Schema::workspacesTable() . " w LEFT JOIN {$wpdb->users} u ON u.ID = w.owner_user_id LEFT JOIN " . Schema::subscriptionsTable() . ' s ON s.workspace_id = w.id LEFT JOIN ' . Schema::qrcodesTable() . ' q ON q.workspace_id = w.id LEFT JOIN ' . Schema::campaignsTable() . ' c ON c.workspace_id = w.id LEFT JOIN ' . Schema::scansTable() . ' sc ON sc.qr_id = q.id ' . $where . ' GROUP BY w.id ORDER BY w.updated_at DESC LIMIT %d OFFSET %d', ...$params)) ?: [];
    }

    public function workspaceCount(string $search = ''): int {
        global $wpdb;
        [$where, $params] = $this->workspaceWhere($search);
        return (int) ($params ? $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT w.id) FROM ' . Schema::workspacesTable() . " w LEFT JOIN {$wpdb->users} u ON u.ID = w.owner_user_id " . $where, ...$params)) : $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::workspacesTable() . ' w ' . $where));
    }

    public function workspaceDetail(int $workspaceId): ?object {
        global $wpdb;
        $rows = $this->workspaces((string) $workspaceId, 1, 1);
        return $rows[0] ?? $wpdb->get_row($wpdb->prepare("SELECT w.*, u.display_name AS owner_name, u.user_email AS owner_email, s.status AS subscription_status FROM " . Schema::workspacesTable() . " w LEFT JOIN {$wpdb->users} u ON u.ID = w.owner_user_id LEFT JOIN " . Schema::subscriptionsTable() . ' s ON s.workspace_id = w.id WHERE w.id = %d LIMIT 1', $workspaceId));
    }

    public function subscriptions(string $status = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        $where = 'WHERE 1=1'; $params = [];
        if ($status !== '') { $where .= ' AND s.status = %s'; $params[] = sanitize_key($status); }
        $params[] = $perPage; $params[] = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results($wpdb->prepare("SELECT s.*, w.name AS workspace_name, w.plan_key AS workspace_plan, u.display_name AS user_name, u.user_email FROM " . Schema::subscriptionsTable() . " s LEFT JOIN " . Schema::workspacesTable() . " w ON w.id = s.workspace_id LEFT JOIN {$wpdb->users} u ON u.ID = s.user_id {$where} ORDER BY s.updated_at DESC LIMIT %d OFFSET %d", ...$params)) ?: [];
    }

    public function subscriptionCounts(): array {
        global $wpdb;
        $rows = $wpdb->get_results('SELECT status, COUNT(*) AS total FROM ' . Schema::subscriptionsTable() . ' GROUP BY status') ?: [];
        $counts = ['active' => 0, 'trialing' => 0, 'past_due' => 0, 'cancelled' => 0, 'incomplete' => 0, 'expired' => 0, 'free' => 0];
        foreach ($rows as $row) { $counts[(string) $row->status] = (int) $row->total; }
        $counts['free_plan_workspaces'] = (int) $wpdb->get_var("SELECT COUNT(*) FROM " . Schema::workspacesTable() . " WHERE plan_key = 'free'");
        return $counts;
    }

    public function qrs(string $search = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        [$where, $params] = $this->qrWhere($search);
        $params[] = $perPage; $params[] = max(0, ($page - 1) * $perPage);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT q.*, w.name AS workspace_name, u.user_email AS owner_email, c.name AS campaign_name, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan FROM " . Schema::qrcodesTable() . " q LEFT JOIN " . Schema::workspacesTable() . " w ON w.id = q.workspace_id LEFT JOIN {$wpdb->users} u ON u.ID = w.owner_user_id LEFT JOIN " . Schema::campaignsTable() . ' c ON c.id = q.campaign_id LEFT JOIN ' . Schema::scansTable() . ' s ON s.qr_id = q.id ' . $where . ' GROUP BY q.id ORDER BY q.updated_at DESC LIMIT %d OFFSET %d', ...$params)) ?: [];
        return array_map([QRCode::class, 'fromRow'], $rows);
    }

    public function qrCount(string $search = ''): int {
        global $wpdb;
        [$where, $params] = $this->qrWhere($search);
        return (int) ($params ? $wpdb->get_var($wpdb->prepare('SELECT COUNT(DISTINCT q.id) FROM ' . Schema::qrcodesTable() . ' q LEFT JOIN ' . Schema::workspacesTable() . ' w ON w.id = q.workspace_id ' . $where, ...$params)) : $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' q ' . $where));
    }

    public function qrDetail(int $qrId): ?QRCode {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT q.*, c.name AS campaign_name, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan FROM ' . Schema::qrcodesTable() . ' q LEFT JOIN ' . Schema::campaignsTable() . ' c ON c.id = q.campaign_id LEFT JOIN ' . Schema::scansTable() . ' s ON s.qr_id = q.id WHERE q.id = %d GROUP BY q.id LIMIT 1', $qrId));
        return $row ? QRCode::fromRow($row) : null;
    }

    public function campaigns(string $search = '', int $page = 1, int $perPage = 25): array {
        global $wpdb;
        $where = 'WHERE 1=1'; $params = [];
        if ($search !== '') { $where .= ' AND (c.name LIKE %s OR w.name LIKE %s OR u.user_email LIKE %s)'; $like = '%' . $wpdb->esc_like($search) . '%'; $params = [$like, $like, $like]; }
        $params[] = $perPage; $params[] = max(0, ($page - 1) * $perPage);
        return $wpdb->get_results($wpdb->prepare("SELECT c.*, w.name AS workspace_name, u.user_email AS owner_email, COUNT(DISTINCT q.id) AS qr_count, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_activity FROM " . Schema::campaignsTable() . " c LEFT JOIN " . Schema::workspacesTable() . " w ON w.id = c.workspace_id LEFT JOIN {$wpdb->users} u ON u.ID = w.owner_user_id LEFT JOIN " . Schema::qrcodesTable() . ' q ON q.campaign_id = c.id LEFT JOIN ' . Schema::scansTable() . ' s ON s.qr_id = q.id ' . $where . ' GROUP BY c.id ORDER BY c.updated_at DESC LIMIT %d OFFSET %d', ...$params)) ?: [];
    }

    public function productAnalytics(): array {
        global $wpdb;
        $qrTypes = $wpdb->get_results('SELECT type AS label, COUNT(*) AS count FROM ' . Schema::qrcodesTable() . ' GROUP BY type ORDER BY count DESC') ?: [];
        $dotStyles = $wpdb->get_results('SELECT dot_style AS label, COUNT(*) AS count FROM ' . Schema::qrcodesTable() . ' GROUP BY dot_style ORDER BY count DESC') ?: [];
        $finderStyles = $wpdb->get_results('SELECT finder_style AS label, COUNT(*) AS count FROM ' . Schema::qrcodesTable() . ' GROUP BY finder_style ORDER BY count DESC') ?: [];
        return ['qr_types' => $qrTypes, 'dot_styles' => $dotStyles, 'finder_styles' => $finderStyles, 'logos' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' WHERE logo_attachment_id IS NOT NULL AND logo_attachment_id > 0'), 'smart_destinations' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::destinationRulesTable()), 'campaigns' => (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::campaignsTable())];
    }

    public function recentScansForQr(int $qrId, int $limit = 10): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::scansTable() . ' WHERE qr_id = %d ORDER BY scanned_at DESC LIMIT %d', $qrId, $limit)) ?: [];
        foreach ($rows as $row) { $row->user_agent_summary = $this->parser->summary((string) $row->user_agent); }
        return $rows;
    }

    private function userWhere(string $search): array {
        global $wpdb;
        if ($search === '') { return ['', []]; }
        if (ctype_digit($search)) { return ['WHERE u.ID = %d', [(int) $search]]; }
        $like = '%' . $wpdb->esc_like($search) . '%';
        return ['WHERE u.user_email LIKE %s OR u.display_name LIKE %s OR w.name LIKE %s', [$like, $like, $like]];
    }

    private function workspaceWhere(string $search): array {
        global $wpdb;
        if ($search === '') { return ['WHERE 1=1', []]; }
        if (ctype_digit($search)) { return ['WHERE w.id = %d', [(int) $search]]; }
        $like = '%' . $wpdb->esc_like($search) . '%';
        return ['WHERE w.name LIKE %s OR w.slug LIKE %s OR u.user_email LIKE %s OR u.display_name LIKE %s', [$like, $like, $like, $like]];
    }

    private function qrWhere(string $search): array {
        global $wpdb;
        if ($search === '') { return ['WHERE 1=1', []]; }
        if (ctype_digit($search)) { return ['WHERE q.id = %d', [(int) $search]]; }
        $like = '%' . $wpdb->esc_like($search) . '%';
        return ['WHERE q.name LIKE %s OR q.shortcode LIKE %s OR q.destination_url LIKE %s OR w.name LIKE %s', [$like, $like, $like, $like]];
    }

    private function webhookSecretConfigured(): bool { return (defined('QR_BUZZ_STRIPE_WEBHOOK_SECRET') && QR_BUZZ_STRIPE_WEBHOOK_SECRET) || getenv('QR_BUZZ_STRIPE_WEBHOOK_SECRET'); }
    public function environmentLabel(): string { return defined('WP_ENVIRONMENT_TYPE') ? (string) WP_ENVIRONMENT_TYPE : (function_exists('wp_get_environment_type') ? wp_get_environment_type() : 'production'); }
}
