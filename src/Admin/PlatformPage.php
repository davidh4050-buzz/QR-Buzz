<?php
namespace QRBuzz\Admin;

use QRBuzz\Membership\EntitlementService;
use QRBuzz\Membership\PlanRegistry;
use QRBuzz\QR\QRGenerator;
use QRBuzz\Workspace\WorkspaceService;

class PlatformPage {

    private WorkspaceService $workspaces;
    private PlanRegistry $plans;
    private EntitlementService $entitlements;

    public function __construct(?WorkspaceService $workspaces = null, ?PlanRegistry $plans = null, ?EntitlementService $entitlements = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
        $this->plans = $plans ?: new PlanRegistry();
        $this->entitlements = $entitlements ?: new EntitlementService($this->workspaces, $this->plans);
    }

    public function init(): void {
        add_action('admin_init', [$this, 'handleRequest'], 1);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        add_submenu_page('qr-buzz', 'Workspace and Plan', 'Workspace & Plan', 'manage_options', 'qr-buzz-platform', [$this, 'render']);
        add_submenu_page('qr-buzz', 'System Diagnostics', 'Diagnostics', 'manage_options', 'qr-buzz-diagnostics', [$this, 'renderDiagnostics']);
    }

    public function assets(string $hook): void {
        if (!in_array($hook, ['qr-buzz_page_qr-buzz-platform', 'qr-buzz_page_qr-buzz-diagnostics'], true)) { return; }
        wp_register_style('qrbuzz-platform', false, [], QR_BUZZ_VERSION);
        wp_enqueue_style('qrbuzz-platform');
        wp_add_inline_style('qrbuzz-platform', '.qrbuzz-dashboard{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0}.qrbuzz-card,.qrbuzz-panel{background:#fff;border:1px solid #c3c4c7;border-radius:4px;padding:14px}.qrbuzz-card strong{display:block;font-size:22px}.qrbuzz-badge{display:inline-block;border-radius:999px;background:#f0f0f1;padding:2px 8px}.qrbuzz-unavailable{color:#8a2424}.qrbuzz-copy-area{width:100%;min-height:220px;font-family:monospace}.qrbuzz-actions{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0}');
    }

    public function handleRequest(): void {
        if (!current_user_can('manage_options')) { return; }
        $this->enforceAdminLimits();
        if (!$this->isPlatformPage()) { return; }
        $action = isset($_REQUEST['qrbuzz_platform_action']) ? sanitize_key(wp_unslash($_REQUEST['qrbuzz_platform_action'])) : '';
        if ($action === 'save_plan' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('qrbuzz_save_plan', 'qrbuzz_plan_nonce');
            $plan = isset($_POST['plan_key']) ? sanitize_key(wp_unslash($_POST['plan_key'])) : 'free';
            if (!in_array($plan, $this->plans->keys(), true)) { $plan = 'free'; }
            $this->workspaces->changePlan($plan);
            wp_safe_redirect(add_query_arg('qrbuzz_notice', 'plan_saved', admin_url('admin.php?page=qr-buzz-platform')));
            exit;
        }
    }

    public function render(): void {
        $this->guard();
        $workspace = $this->workspaces->current();
        $summary = $this->entitlements->summary();
        echo '<div class="wrap"><h1>Workspace & Plan</h1>';
        $this->notice();
        echo '<div class="qrbuzz-panel"><h2>' . esc_html($workspace->name) . '</h2><p><span class="qrbuzz-badge">Local development plan assignment</span></p><p>Plans are currently assigned locally for development and testing. Live subscriptions will be implemented later.</p><form method="post" action="' . esc_url(admin_url('admin.php?page=qr-buzz-platform')) . '"><input type="hidden" name="qrbuzz_platform_action" value="save_plan" />';
        wp_nonce_field('qrbuzz_save_plan', 'qrbuzz_plan_nonce');
        echo '<label for="qrbuzz-plan">Current plan </label><select id="qrbuzz-plan" name="plan_key">';
        foreach ($this->plans->plans() as $key => $plan) { echo '<option value="' . esc_attr($key) . '" ' . selected($workspace->planKey, $key, false) . '>' . esc_html($plan['label']) . '</option>'; }
        echo '</select> ';
        submit_button('Save Plan', 'secondary', '', false);
        echo '</form></div>';
        echo '<div class="qrbuzz-dashboard">';
        foreach ($summary['usage'] as $resource => $used) { if ($used === null) { continue; } $limit = $summary['limits'][$resource]; echo '<div class="qrbuzz-card"><span>' . esc_html(ucwords(str_replace('_', ' ', $resource))) . '</span><strong>' . esc_html((string) $used) . '</strong><p>Limit: ' . esc_html($limit === null ? 'Unlimited' : (string) $limit) . '</p></div>'; }
        echo '</div>';
        $this->renderExports();
        echo '<div class="qrbuzz-panel"><h2>Features</h2><table class="widefat striped"><thead><tr><th>Feature</th><th>Status</th></tr></thead><tbody>';
        foreach ($summary['features'] as $feature => $enabled) { echo '<tr><td>' . esc_html(ucwords(str_replace('_', ' ', $feature))) . '</td><td>' . ($enabled ? 'Available' : '<span class="qrbuzz-unavailable">Unavailable on this plan</span>') . '</td></tr>'; }
        echo '</tbody></table></div></div>';
    }

    public function renderDiagnostics(): void {
        $this->guard();
        global $wpdb;
        $workspace = $this->workspaces->current();
        $generator = new QRGenerator();
        $data = [
            'QR Buzz version' => QR_BUZZ_VERSION,
            'Schema version' => (string) get_option('qrbuzz_db_version', 'unknown'),
            'WordPress version' => get_bloginfo('version'),
            'PHP version' => PHP_VERSION,
            'Database version' => method_exists($wpdb, 'db_version') ? $wpdb->db_version() : 'unknown',
            'Active workspace' => $workspace->name . ' (#' . $workspace->id . ')',
            'Assigned plan' => $this->plans->label($workspace->planKey),
            'QR renderer' => class_exists('Endroid\\QrCode\\QrCode') ? 'Available' : 'Unavailable',
            'SVG downloads' => $generator->supportsSvg() ? 'Available' : 'Unavailable',
            'REST namespace' => 'qr-buzz/v1 registered',
            'Scheduled tasks' => 'None registered in v0.9.0',
        ];
        echo '<div class="wrap"><h1>System Diagnostics</h1><p>This excludes secrets, tokens, raw IP addresses, and personal scan data.</p><table class="widefat striped"><tbody>';
        foreach ($data as $label => $value) { echo '<tr><th>' . esc_html($label) . '</th><td>' . esc_html((string) $value) . '</td></tr>'; }
        echo '</tbody></table><h2>Copy diagnostics</h2><textarea class="qrbuzz-copy-area" readonly>' . esc_textarea(wp_json_encode($data, JSON_PRETTY_PRINT)) . '</textarea></div>';
    }

    private function renderExports(): void {
        echo '<div class="qrbuzz-panel"><h2>Exports</h2>';
        if ($this->entitlements->allows('csv_export')) {
            $assetsUrl = wp_nonce_url(admin_url('admin-post.php?action=qrbuzz_export_assets'), 'qrbuzz_export_assets');
            $campaignsUrl = wp_nonce_url(admin_url('admin-post.php?action=qrbuzz_export_campaigns'), 'qrbuzz_export_campaigns');
            echo '<p>Download workspace-scoped CSV exports for reporting or backups.</p><div class="qrbuzz-actions"><a class="button" href="' . esc_url($assetsUrl) . '">Export QR assets CSV</a><a class="button" href="' . esc_url($campaignsUrl) . '">Export campaigns CSV</a></div>';
        } else {
            echo '<p><span class="qrbuzz-unavailable">CSV exports are unavailable on the current plan.</span></p>';
        }
        echo '</div>';
    }

    private function enforceAdminLimits(): void {
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
        if ($page === 'qr-buzz-create' || $page === 'qr-buzz-codes') {
            $action = isset($_POST['qrbuzz_action']) ? sanitize_key(wp_unslash($_POST['qrbuzz_action'])) : '';
            if ($action === 'save') { $this->enforceQrSave(); }
            if ($action === 'save_rule') { $this->enforceRuleSave(); }
        }
        if ($page === 'qr-buzz-campaigns' && isset($_POST['qrbuzz_campaign_action']) && sanitize_key(wp_unslash($_POST['qrbuzz_campaign_action'])) === 'save') { $this->enforceCampaignSave(); }
    }

    private function enforceQrSave(): void {
        $id = isset($_POST['qr_id']) ? absint($_POST['qr_id']) : 0;
        $type = isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : 'dynamic_url';
        if ($id <= 0 && !$this->entitlements->canCreate('qr_assets')) { $this->blocked('Your current plan has reached its QR asset limit. Delete an existing asset or change the workspace plan.'); }
        if ($id <= 0 && $type === 'dynamic_url' && !$this->entitlements->canCreate('dynamic_qr_assets')) { $this->blocked('Your current plan has reached its dynamic QR asset limit. Delete an existing asset or change the workspace plan.'); }
        if ($type !== 'dynamic_url' && !$this->entitlements->allows('static_qr')) { $this->blocked('Static QR assets are not available on the current plan.'); }
        if ($type === 'dynamic_url' && !$this->entitlements->allows('dynamic_qr')) { $this->blocked('Dynamic QR assets are not available on the current plan.'); }
        if (!empty($_POST['logo_attachment_id']) && !$this->entitlements->allows('logo_embedding')) { $this->blocked('Logo embedding is not available on the current plan.'); }
        $usesAdvancedBranding = !empty($_POST['transparent_background']) || (isset($_POST['theme']) && sanitize_key(wp_unslash($_POST['theme'])) !== 'classic');
        if ($usesAdvancedBranding && !$this->entitlements->allows('advanced_branding')) { $this->blocked('Advanced branding is not available on the current plan.'); }
    }

    private function enforceCampaignSave(): void {
        $id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        if (!$this->entitlements->allows('campaigns')) { $this->blocked('Campaigns are not available on the current plan.'); }
        if ($id <= 0 && !$this->entitlements->canCreate('campaigns')) { $this->blocked('Your current plan has reached its campaign limit. Delete an existing campaign or change the workspace plan.'); }
    }

    private function enforceRuleSave(): void {
        $qrId = isset($_POST['qr_id']) ? absint($_POST['qr_id']) : 0;
        if (!$this->entitlements->canCreateSmartRule($qrId)) { $this->blocked('Smart Destination rules are not available or have reached the limit for this QR asset.'); }
    }

    private function blocked(string $message): void { wp_die(esc_html($message), esc_html__('QR Buzz plan limit', 'qr-buzz'), ['response' => 403]); }
    private function notice(): void { if (isset($_GET['qrbuzz_notice']) && sanitize_key(wp_unslash($_GET['qrbuzz_notice'])) === 'plan_saved') { echo '<div class="notice notice-success is-dismissible"><p>Workspace plan saved.</p></div>'; } }
    private function isPlatformPage(): bool { return isset($_REQUEST['page']) && sanitize_key(wp_unslash($_REQUEST['page'])) === 'qr-buzz-platform'; }
    private function guard(): void { if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to access QR Buzz platform settings.', 'qr-buzz')); } }
}
