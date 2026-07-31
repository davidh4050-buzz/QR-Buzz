<?php
namespace QRBuzz\Database;

use QRBuzz\Models\QRCode;
use QRBuzz\Platform\PlatformEventRepository;
use QRBuzz\QR\Design\QRDesignSettings;
use QRBuzz\QR\Types\QRTypeRegistry;
use QRBuzz\Redirect\Resolution;
use QRBuzz\Utils\ShortcodeGenerator;
use QRBuzz\Workspace\WorkspaceService;

class QRRepository {

    private ShortcodeGenerator $shortcodes;
    private QRTypeRegistry $types;
    private WorkspaceService $workspaces;
    private PlatformEventRepository $events;

    public function __construct(?ShortcodeGenerator $shortcodes = null, ?QRTypeRegistry $types = null, ?WorkspaceService $workspaces = null, ?PlatformEventRepository $events = null) {
        $this->shortcodes = $shortcodes ?: new ShortcodeGenerator();
        $this->types = $types ?: new QRTypeRegistry();
        $this->workspaces = $workspaces ?: new WorkspaceService();
        $this->events = $events ?: new PlatformEventRepository();
    }

    /** @return QRCode[] */
    public function allWithScanCounts(): array { return $this->queryWithScanCounts(); }

    /** @return QRCode[] */
    public function queryWithScanCounts(string $search = '', int $page = 1, int $perPage = 20, string $orderby = 'created_at', string $order = 'DESC', int $campaignId = 0): array {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $campaignsTable = Schema::campaignsTable();
        $orderby = $this->allowedOrderby($orderby);
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $offset = max(0, ($page - 1) * $perPage);
        [$where, $params] = $this->managementWhere($search, $campaignId);

        $sql = "SELECT q.*, c.name AS campaign_name, COALESCE(stats.scan_count, 0) AS scan_count, stats.last_scan
             FROM {$qrcodesTable} q
             LEFT JOIN {$campaignsTable} c ON c.id = q.campaign_id AND c.workspace_id = q.workspace_id
             LEFT JOIN (
                SELECT qr_id, COUNT(id) AS scan_count, MAX(scanned_at) AS last_scan
                FROM {$scansTable}
                GROUP BY qr_id
             ) stats ON stats.qr_id = q.id
             {$where}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d";
        $params[] = $perPage;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
        return array_map([QRCode::class, 'fromRow'], $rows ?: []);
    }

    public function count(string $search = '', int $campaignId = 0): int {
        global $wpdb;
        [$where, $params] = $this->managementWhere($search, $campaignId);
        $sql = 'SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . " q {$where}";
        return (int) ($params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql));
    }

    public function countTrackable(bool $trackable): int {
        global $wpdb;
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' WHERE workspace_id = %d AND is_trackable = %d', $this->workspaceId(), $trackable ? 1 : 0));
    }

    public function find(int $id): ?QRCode {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $campaignsTable = Schema::campaignsTable();
        $row = $wpdb->get_row($wpdb->prepare(
            "SELECT q.*, c.name AS campaign_name, COALESCE(stats.scan_count, 0) AS scan_count, stats.last_scan
             FROM {$qrcodesTable} q
             LEFT JOIN {$campaignsTable} c ON c.id = q.campaign_id AND c.workspace_id = q.workspace_id
             LEFT JOIN (
                SELECT qr_id, COUNT(id) AS scan_count, MAX(scanned_at) AS last_scan
                FROM {$scansTable}
                GROUP BY qr_id
             ) stats ON stats.qr_id = q.id
             WHERE q.id = %d AND q.workspace_id = %d
             LIMIT 1",
            $id,
            $this->workspaceId()
        ));
        return $row ? QRCode::fromRow($row) : null;
    }

    public function findByShortcode(string $shortcode): ?QRCode {
        global $wpdb;
        $row = $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::qrcodesTable() . ' WHERE shortcode = %s LIMIT 1', $shortcode));
        return $row ? QRCode::fromRow($row) : null;
    }

    public function create(string $name, string $destinationUrl, array $settings = []): int {
        global $wpdb;
        $now = current_time('mysql');
        $status = $this->normalizeStatus($settings['status'] ?? 'active');
        $type = $this->types->normalize((string) ($settings['type'] ?? QRTypeRegistry::DYNAMIC_URL));
        $isTrackable = $this->types->isTrackable($type);
        $payloadData = $this->payloadData($settings['payload_data'] ?? []);
        $staticPayload = $this->nullableString($settings['static_payload'] ?? ($isTrackable ? $destinationUrl : ''));
        $design = new QRDesignSettings($settings['design'] ?? []);
        $wpdb->insert(Schema::qrcodesTable(), [
            'workspace_id' => $this->workspaceId(),
            'name' => $name,
            'destination_url' => $destinationUrl,
            'shortcode' => $this->uniqueShortcode(),
            'created_at' => $now,
            'updated_at' => $now,
            'active' => $status === 'active' && $isTrackable ? 1 : 0,
            'status' => $isTrackable ? $status : 'active',
            'fallback_url' => $isTrackable ? $this->nullableUrl($settings['fallback_url'] ?? null) : null,
            'expires_at' => $isTrackable ? $this->nullableString($settings['expires_at'] ?? null) : null,
            'scheduled_url' => $isTrackable ? $this->nullableUrl($settings['scheduled_url'] ?? null) : null,
            'scheduled_start_at' => $isTrackable ? $this->nullableString($settings['scheduled_start_at'] ?? null) : null,
            'scheduled_end_at' => $isTrackable ? $this->nullableString($settings['scheduled_end_at'] ?? null) : null,
            'type' => $type,
            'payload_data' => $payloadData,
            'static_payload' => $staticPayload,
            'is_trackable' => $isTrackable ? 1 : 0,
            'campaign_id' => $this->nullableId($settings['campaign_id'] ?? 0),
            'theme' => $design->theme,
            'foreground_color' => $design->foregroundColor,
            'background_color' => $design->backgroundColor,
            'transparent_background' => $design->transparentBackground ? 1 : 0,
            'error_correction' => $design->errorCorrection,
            'margin' => $design->margin,
            'logo_attachment_id' => $this->nullableId($design->logoAttachmentId),
            'logo_size' => $design->logoSize,
            'dot_style' => $design->dotStyle,
            'finder_style' => $design->finderStyle,
            'finder_dot_style' => $design->finderDotStyle,
            'finder_color' => $design->finderColor,
            'caption' => $design->caption,
            'caption_font_size' => $design->captionFontSize,
            'caption_font_color' => $design->captionFontColor,
        ], ['%d','%s','%s','%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%d','%s','%d','%d','%d','%s','%s','%s','%s','%s','%d','%s']);
        $id = (int) $wpdb->insert_id;
        if ($id > 0) { $this->events->record('qr_created', ['user_id' => get_current_user_id(), 'workspace_id' => $this->workspaceId(), 'object_type' => 'qr', 'object_id' => $id, 'metadata' => ['type' => $type]]); }
        return $id;
    }

    public function update(int $id, string $name, string $destinationUrl, string $status, array $settings = [], int $userId = 0): bool {
        global $wpdb;
        $before = $this->find($id);
        if (!$before) { return false; }
        $status = $this->normalizeStatus($status);
        $type = $this->types->normalize((string) ($settings['type'] ?? $before->type));
        $isTrackable = $this->types->isTrackable($type);
        $design = new QRDesignSettings($settings['design'] ?? []);
        $data = [
            'name' => $name,
            'destination_url' => $destinationUrl,
            'updated_at' => current_time('mysql'),
            'active' => $status === 'active' && $isTrackable ? 1 : 0,
            'status' => $isTrackable ? $status : 'active',
            'fallback_url' => $isTrackable ? $this->nullableUrl($settings['fallback_url'] ?? null) : null,
            'expires_at' => $isTrackable ? $this->nullableString($settings['expires_at'] ?? null) : null,
            'scheduled_url' => $isTrackable ? $this->nullableUrl($settings['scheduled_url'] ?? null) : null,
            'scheduled_start_at' => $isTrackable ? $this->nullableString($settings['scheduled_start_at'] ?? null) : null,
            'scheduled_end_at' => $isTrackable ? $this->nullableString($settings['scheduled_end_at'] ?? null) : null,
            'type' => $type,
            'payload_data' => $this->payloadData($settings['payload_data'] ?? []),
            'static_payload' => $this->nullableString($settings['static_payload'] ?? ($isTrackable ? $destinationUrl : '')),
            'is_trackable' => $isTrackable ? 1 : 0,
            'campaign_id' => $this->nullableId($settings['campaign_id'] ?? 0),
            'theme' => $design->theme,
            'foreground_color' => $design->foregroundColor,
            'background_color' => $design->backgroundColor,
            'transparent_background' => $design->transparentBackground ? 1 : 0,
            'error_correction' => $design->errorCorrection,
            'margin' => $design->margin,
            'logo_attachment_id' => $this->nullableId($design->logoAttachmentId),
            'logo_size' => $design->logoSize,
            'dot_style' => $design->dotStyle,
            'finder_style' => $design->finderStyle,
            'finder_dot_style' => $design->finderDotStyle,
            'finder_color' => $design->finderColor,
            'caption' => $design->caption,
            'caption_font_size' => $design->captionFontSize,
            'caption_font_color' => $design->captionFontColor,
        ];
        $result = $wpdb->update(Schema::qrcodesTable(), $data, ['id' => $id, 'workspace_id' => $this->workspaceId()], ['%s','%s','%s','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%d','%s','%s','%s','%d','%s','%d','%d','%d','%s','%s','%s','%s','%s','%d','%s'], ['%d','%d']);
        if ($result === false) { return false; }
        $this->recordDestinationChanges($before, $data, $userId);
        $this->events->record('qr_updated', ['user_id' => $userId ?: get_current_user_id(), 'workspace_id' => $this->workspaceId(), 'object_type' => 'qr', 'object_id' => $id, 'metadata' => ['type' => $type]]);
        return true;
    }

    public function setActive(int $id, bool $active): bool {
        global $wpdb;
        $before = $this->find($id);
        if ($before && !$before->isTrackable()) { return true; }
        $status = $active ? 'active' : 'paused';
        $result = $wpdb->update(Schema::qrcodesTable(), ['active' => $active ? 1 : 0, 'status' => $status, 'updated_at' => current_time('mysql')], ['id' => $id, 'workspace_id' => $this->workspaceId()], ['%d', '%s', '%s'], ['%d', '%d']);
        if ($result === false) { return false; }
        if ($before && $before->status !== $status) { $this->recordHistory($id, 'status_changed', $before->status, $status, get_current_user_id()); }
        return true;
    }

    public function delete(int $id): bool {
        global $wpdb;
        $before = $this->find($id);
        if (!$before) { return false; }
        $wpdb->delete(Schema::scansTable(), ['qr_id' => $id], ['%d']);
        $wpdb->delete(Schema::destinationHistoryTable(), ['qr_id' => $id], ['%d']);
        $wpdb->delete(Schema::destinationRulesTable(), ['qr_id' => $id, 'workspace_id' => $this->workspaceId()], ['%d','%d']);
        return $wpdb->delete(Schema::qrcodesTable(), ['id' => $id, 'workspace_id' => $this->workspaceId()], ['%d','%d']) !== false;
    }

    public function logScan(QRCode $qrCode, array $server, ?Resolution $resolution = null): void {
        global $wpdb;
        if (!$qrCode->isTrackable()) { return; }
        $wpdb->insert(Schema::scansTable(), [
            'qr_id' => $qrCode->id,
            'scanned_at' => current_time('mysql'),
            'ip_hash' => $this->hashIp((string) ($server['REMOTE_ADDR'] ?? '')),
            'user_agent' => sanitize_textarea_field((string) ($server['HTTP_USER_AGENT'] ?? '')),
            'referrer' => esc_url_raw((string) ($server['HTTP_REFERER'] ?? '')),
            'country' => null,
            'destination_resolved' => $resolution ? $resolution->destinationUrl : null,
            'resolution_reason' => $resolution ? $resolution->reason : null,
            'scan_status' => $resolution ? $resolution->scanStatus : null,
        ], ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']);
    }

    public function scanCount(int $qrId): int { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::scansTable() . ' WHERE qr_id = %d', $qrId)); }
    public function lastScan(int $qrId): ?string { global $wpdb; $lastScan = $wpdb->get_var($wpdb->prepare('SELECT MAX(scanned_at) FROM ' . Schema::scansTable() . ' WHERE qr_id = %d', $qrId)); return $lastScan ? (string) $lastScan : null; }

    /** @return object[] */
    public function recentScans(int $limit = 10): array {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        return $wpdb->get_results($wpdb->prepare("SELECT s.*, q.name AS qr_name, q.shortcode FROM {$scansTable} s INNER JOIN {$qrcodesTable} q ON q.id = s.qr_id WHERE q.workspace_id = %d ORDER BY s.scanned_at DESC LIMIT %d", $this->workspaceId(), $limit)) ?: [];
    }

    /** @return object[] */
    public function destinationHistory(int $qrId, int $limit = 10): array {
        global $wpdb;
        return $wpdb->get_results($wpdb->prepare('SELECT h.*, u.display_name AS user_name FROM ' . Schema::destinationHistoryTable() . ' h LEFT JOIN ' . $wpdb->users . ' u ON u.ID = h.changed_by INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = h.qr_id WHERE h.qr_id = %d AND q.workspace_id = %d ORDER BY h.changed_at DESC LIMIT %d', $qrId, $this->workspaceId(), $limit)) ?: [];
    }

    /** @return QRCode[] */
    public function recentQrCodes(int $limit = 5): array { return $this->queryWithScanCounts('', 1, $limit, 'created_at', 'DESC'); }
    public function totalScans(): int { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(s.id) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE q.workspace_id = %d', $this->workspaceId())); }
    public function latestScan(): ?string { global $wpdb; $latest = $wpdb->get_var($wpdb->prepare('SELECT MAX(s.scanned_at) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE q.workspace_id = %d', $this->workspaceId())); return $latest ? (string) $latest : null; }
    public function mostScanned(): ?QRCode { $results = $this->queryWithScanCounts('', 1, 1, 'scan_count', 'DESC'); return $results[0] ?? null; }

    public function dashboardSummary(): array {
        return ['total_qr_codes' => $this->count(), 'dynamic_qr_codes' => $this->countTrackable(true), 'static_qr_codes' => $this->countTrackable(false), 'total_scans' => $this->totalScans(), 'most_scanned' => $this->mostScanned(), 'latest_scan' => $this->latestScan(), 'recent_qr_codes' => $this->recentQrCodes(), 'recent_scans' => $this->recentScans(5)];
    }

    private function recordDestinationChanges(QRCode $before, array $after, int $userId): void {
        $changes = ['destination_url' => ['primary_destination_updated', $before->destinationUrl], 'scheduled_url' => ['scheduled_destination_updated', $before->scheduledUrl], 'scheduled_start_at' => ['scheduled_destination_updated', $before->scheduledStartAt], 'scheduled_end_at' => ['scheduled_destination_updated', $before->scheduledEndAt], 'fallback_url' => ['fallback_updated', $before->fallbackUrl], 'status' => ['status_changed', $before->status], 'expires_at' => ['expiry_updated', $before->expiresAt]];
        foreach ($changes as $field => [$changeType, $previous]) { $new = $after[$field] ?? null; if ((string) $previous === (string) $new) { continue; } $this->recordHistory($before->id, $changeType, $previous, $new, $userId); }
    }

    private function recordHistory(int $qrId, string $changeType, $previous, $new, int $userId): void { global $wpdb; $wpdb->insert(Schema::destinationHistoryTable(), ['qr_id' => $qrId, 'change_type' => $changeType, 'previous_value' => $previous === null ? null : (string) $previous, 'new_value' => $new === null ? null : (string) $new, 'changed_by' => $userId > 0 ? $userId : null, 'changed_at' => current_time('mysql')], ['%d', '%s', '%s', '%s', '%d', '%s']); }
    private function uniqueShortcode(): string { do { $shortcode = $this->shortcodes->generate(); } while ($this->findByShortcode($shortcode) !== null); return $shortcode; }

    private function managementWhere(string $search, int $campaignId): array {
        global $wpdb;
        $where = ['q.workspace_id = %d'];
        $params = [$this->workspaceId()];
        if ($search !== '') { $where[] = 'q.name LIKE %s'; $params[] = '%' . $wpdb->esc_like($search) . '%'; }
        if ($campaignId > 0) { $where[] = 'q.campaign_id = %d'; $params[] = $campaignId; }
        return ['WHERE ' . implode(' AND ', $where), $params];
    }

    private function workspaceId(): int { return $this->workspaces->id(); }
    private function hashIp(string $ip): string { return hash_hmac('sha256', $ip, wp_salt('auth')); }
    private function normalizeStatus(string $status): string { return in_array($status, ['active', 'paused'], true) ? $status : 'active'; }
    private function nullableUrl($url): ?string { $url = trim((string) $url); return $url === '' ? null : esc_url_raw($url); }
    private function nullableString($value): ?string { $value = trim((string) $value); return $value === '' ? null : $value; }
    private function nullableId($value): ?int { $id = absint($value); return $id > 0 ? $id : null; }
    private function payloadData($value): ?string { return wp_json_encode(is_array($value) ? $value : []); }
    private function allowedOrderby(string $orderby): string { $allowed = ['name' => 'q.name', 'created_at' => 'q.created_at', 'updated_at' => 'q.updated_at', 'active' => 'q.active', 'scan_count' => 'scan_count', 'type' => 'q.type', 'campaign' => 'c.name']; return $allowed[$orderby] ?? $allowed['created_at']; }
}
