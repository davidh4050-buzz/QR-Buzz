<?php
namespace QRBuzz\Platform;

use QRBuzz\Billing\SubscriptionRepository;
use QRBuzz\Database\Schema;
use QRBuzz\Membership\EntitlementService;
use QRBuzz\QR\Design\QRDesignSettings;
use QRBuzz\QR\QRGenerator;
use QRBuzz\QR\Types\QRPayloadService;
use QRBuzz\QR\Types\QRTypeRegistry;
use QRBuzz\Redirect\DestinationResolver;
use QRBuzz\SmartDestinations\RuleConditionFormatter;
use QRBuzz\SmartDestinations\RuleConflictAnalyzer;

class PlatformAdminController {

    private PlatformAdminRepository $repo;
    private PlatformObservationService $observations;
    private PlatformAuditRepository $audit;
    private PlatformErrorRepository $errors;
    private WebhookLogRepository $webhooks;
    private FeatureFlagRepository $flags;
    private SupportActionService $support;
    private HealthCheckService $health;
    private QRGenerator $generator;
    private QRPayloadService $payloads;
    private DestinationResolver $resolver;
    private RuleConditionFormatter $conditionFormatter;
    private RuleConflictAnalyzer $conflicts;

    public function __construct() {
        $this->repo = new PlatformAdminRepository();
        $this->observations = new PlatformObservationService();
        $this->audit = new PlatformAuditRepository();
        $this->errors = new PlatformErrorRepository();
        $this->webhooks = new WebhookLogRepository();
        $this->flags = new FeatureFlagRepository($this->audit);
        $this->support = new SupportActionService($this->audit, null, null, null, $this->errors, $this->webhooks);
        $this->health = new HealthCheckService();
        $this->generator = new QRGenerator();
        $this->payloads = new QRPayloadService(new QRTypeRegistry(), $this->generator);
        $this->resolver = new DestinationResolver();
        $this->conditionFormatter = new RuleConditionFormatter();
        $this->conflicts = new RuleConflictAnalyzer();
    }

    public function init(): void { add_action('template_redirect', [$this, 'route'], 0); }

    public function route(): void {
        $path = $this->path();
        if ($path !== 'platform-admin' && !str_starts_with($path, 'platform-admin/')) { return; }
        if ($_SERVER['REQUEST_METHOD'] === 'POST') { $this->handlePost($path); }
        if (!$this->canAccess()) { $this->unauthorised(); }
        if ($path === 'platform-admin') { wp_safe_redirect(home_url('/platform-admin/dashboard')); exit; }
        if (!empty($_GET['export'])) { $this->export(sanitize_key((string) $_GET['export'])); }
        if ($path === 'platform-admin/dashboard') { $this->page('Overview', $this->dashboard()); }
        if ($path === 'platform-admin/users') { $this->page('Users', $this->users()); }
        if (preg_match('#^platform-admin/users/(\d+)$#', $path, $m)) { $this->page('User detail', $this->userDetail((int) $m[1])); }
        if ($path === 'platform-admin/workspaces') { $this->page('Workspaces', $this->workspaces()); }
        if (preg_match('#^platform-admin/workspaces/(\d+)$#', $path, $m)) { $this->page('Workspace detail', $this->workspaceDetail((int) $m[1])); }
        if ($path === 'platform-admin/subscriptions') { $this->page('Subscriptions', $this->subscriptions()); }
        if ($path === 'platform-admin/qrs') { $this->page('QR Inspector', $this->qrs()); }
        if (preg_match('#^platform-admin/qrs/(\d+)$#', $path, $m)) { $this->page('QR detail', $this->qrDetail((int) $m[1])); }
        if ($path === 'platform-admin/campaigns') { $this->page('Campaigns', $this->campaigns()); }
        if ($path === 'platform-admin/system-health') { $this->page('System Health', $this->systemHealth()); }
        if ($path === 'platform-admin/diagnostics') { $this->page('Diagnostics', $this->diagnostics()); }
        if ($path === 'platform-admin/webhooks') { $this->page('Webhooks', $this->webhooks()); }
        if ($path === 'platform-admin/errors') { $this->page('Errors', $this->errors()); }
        if ($path === 'platform-admin/audit-log') { $this->page('Audit Log', $this->auditLog()); }
        if ($path === 'platform-admin/feature-flags') { $this->page('Feature Flags', $this->featureFlags()); }
        if ($path === 'platform-admin/product-analytics') { $this->page('Product Analytics', $this->productAnalytics()); }
        if ($path === 'platform-admin/settings') { $this->page('Settings', $this->settings()); }
        $this->page('Not found', '<section class="qrb-card"><h1>Page not found</h1></section>', 404);
    }

    private function handlePost(string $path): void {
        if (!$this->canAccess()) { $this->unauthorised(); }
        check_admin_referer('qrbuzz_platform_admin', 'nonce');
        $action = sanitize_key((string) ($_POST['platform_action'] ?? ''));
        $ok = false;
        if ($action === 'refresh_observations') { delete_transient('qrbuzz_platform_observations'); $ok = true; }
        if ($action === 'resend_verification') { $ok = $this->support->resendVerification(absint($_POST['user_id'] ?? 0)); }
        if ($action === 'reset_onboarding' && $this->confirmed()) { $ok = $this->support->resetUserOnboarding(absint($_POST['user_id'] ?? 0)); }
        if ($action === 'suspend_workspace' && $this->confirmed()) { $ok = $this->support->suspendWorkspace(absint($_POST['workspace_id'] ?? 0)); }
        if ($action === 'reactivate_workspace') { $ok = $this->support->reactivateWorkspace(absint($_POST['workspace_id'] ?? 0)); }
        if ($action === 'assign_test_plan' && $this->confirmed()) { $ok = $this->support->assignTestPlan(absint($_POST['workspace_id'] ?? 0), sanitize_key((string) ($_POST['plan_key'] ?? 'free'))); }
        if ($action === 'error_status') { $ok = $this->support->updateErrorStatus(absint($_POST['error_id'] ?? 0), sanitize_key((string) ($_POST['status'] ?? 'new')), sanitize_textarea_field((string) ($_POST['notes'] ?? ''))); }
        if ($action === 'retry_webhook' && $this->confirmed()) { $ok = $this->support->retryWebhook(absint($_POST['webhook_id'] ?? 0)); }
        if ($action === 'feature_flag') { $ok = $this->flags->set(absint($_POST['flag_id'] ?? 0), !empty($_POST['enabled']), get_current_user_id()); }
        if ($action === 'save_settings') { update_option('qrbuzz_platform_settings', $this->settingsFromPost()); $this->audit->record('administrator_action', 'Platform settings saved.', ['entity_type' => 'settings']); $ok = true; }
        if ($action === 'snapshot') { $workspaceId = absint($_POST['workspace_id'] ?? 0); $ok = $this->support->diagnosticSnapshot($workspaceId, $this->diagnosticSummary($workspaceId)); }
        wp_safe_redirect(add_query_arg('notice', $ok ? 'saved' : 'failed', home_url('/' . $path))); exit;
    }

    private function dashboard(): string {
        $summary = $this->repo->summary();
        $html = $this->header('Platform Overview', 'Understand today, spot problems, and support customers quickly.', '<a class="qrb-button" href="/app/dashboard">Customer workspace</a>');
        $html .= $this->metrics([['Users',$summary['users'],'Registered accounts'],['Workspaces',$summary['workspaces'],'Total'],['QR assets',$summary['qrs'],'Across all workspaces'],['Scans 24h',$summary['scans_24h'],'Recent scan volume'],['Paid subscriptions',$summary['paid_subscriptions'],'Active or trialing'],['Open errors',$summary['unresolved_errors'],'Needs review']]);
        $html .= '<section class="qrb-card"><div class="qrb-page-head"><h2>What\'s happening today?</h2>' . $this->postButton('Refresh', 'refresh_observations') . '</div><ul class="qrb-activity-list">';
        foreach ($this->observations->observations(!empty($_GET['refresh'])) as [$text, $url]) { $html .= '<li><span>' . esc_html($text) . '</span><a class="qrb-button" href="' . esc_url(home_url($url)) . '">View</a></li>'; }
        $html .= '</ul></section><div class="qrb-dashboard-grid"><section class="qrb-card"><h2>Attention required</h2>' . $this->attentionList() . '</section><section class="qrb-card"><h2>Recent activity</h2>' . $this->activityList($this->repo->recentActivity()) . '</section></div>';
        return $html;
    }

    private function users(): string {
        $search = $this->search(); $rows = $this->repo->users($search, $this->pageNo());
        $html = $this->header('Users', 'Search and inspect QR Buzz users.', $this->exportLink('users')) . $this->searchForm('/platform-admin/users', $search);
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>User</th><th>Email</th><th>Verified</th><th>Workspace</th><th>Plan</th><th>Onboarding</th><th>Last login</th><th>Created</th></tr></thead><tbody>';
        foreach ($rows as $row) { $html .= '<tr><td><a href="' . esc_url(home_url('/platform-admin/users/' . $row->ID)) . '">' . esc_html($row->display_name ?: 'User #' . $row->ID) . '</a></td><td>' . esc_html($row->user_email) . '</td><td>' . $this->badge(!empty($row->email_verified) ? 'Verified' : 'Unverified', !empty($row->email_verified) ? 'success' : 'warning') . '</td><td>' . esc_html($row->workspace_name ?: '-') . '</td><td>' . esc_html($row->plan_key ?: '-') . '</td><td>' . esc_html($row->onboarding_status ?: '-') . '</td><td>' . esc_html($row->last_login_at ?: '-') . '</td><td>' . esc_html($row->user_registered) . '</td></tr>'; }
        return $html . '</tbody></table></div>' . $this->pager('/platform-admin/users', $this->repo->userCount($search), $search) . '</section>';
    }

    private function userDetail(int $id): string {
        $u = $this->repo->userDetail($id); if (!$u) { return $this->empty('User not found', '/platform-admin/users'); }
        $workspace = $this->workspaceForUser($id);
        $userData = get_userdata($id);
        $roles = $userData ? implode(', ', (array) $userData->roles) : '-';
        $html = $this->header('User: ' . ($u->display_name ?: $u->user_email), 'Account, onboarding, workspace and support diagnostics.', '<a class="qrb-button" href="/platform-admin/users">Users</a>');
        $html .= $this->metrics([['User ID',$u->ID],['Email verified',!empty($u->email_verified) ? 'Yes' : 'No'],['Onboarding',$u->onboarding_status ?: '-'],['Last login',$u->last_login_at ?: '-']]);
        $html .= '<div class="qrb-dashboard-grid"><section class="qrb-card"><h2>Account</h2><p><strong>Email:</strong> ' . esc_html($u->user_email) . '</p><p><strong>Registered:</strong> ' . esc_html($u->user_registered) . '</p><p><strong>Role:</strong> ' . esc_html($roles) . '</p></section><section class="qrb-card"><h2>Support actions</h2><div class="qrb-actions">' . $this->postButton('Resend verification', 'resend_verification', ['user_id' => $id]) . $this->postButton('Reset onboarding', 'reset_onboarding', ['user_id' => $id, 'confirm_required' => 1], true) . '</div></section></div>';
        if ($workspace) { $html .= '<section class="qrb-card"><h2>Workspace</h2><p><a href="' . esc_url(home_url('/platform-admin/workspaces/' . $workspace->id)) . '">' . esc_html($workspace->name) . '</a> · ' . esc_html($workspace->plan_key) . '</p></section>'; }
        return $html;
    }

    private function workspaces(): string {
        $search = $this->search(); $rows = $this->repo->workspaces($search, $this->pageNo());
        $html = $this->header('Workspaces', 'Inspect workspace ownership, usage and health.', $this->exportLink('workspaces')) . $this->searchForm('/platform-admin/workspaces', $search);
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Workspace</th><th>Owner</th><th>Plan</th><th>Status</th><th>QRs</th><th>Campaigns</th><th>Scans</th><th>Last activity</th></tr></thead><tbody>';
        foreach ($rows as $w) { $html .= '<tr><td><a href="' . esc_url(home_url('/platform-admin/workspaces/' . $w->id)) . '">' . esc_html($w->name) . '</a><br><small>' . esc_html($w->slug) . '</small></td><td>' . esc_html($w->owner_email ?: '-') . '</td><td>' . esc_html($w->plan_key) . '</td><td>' . $this->badge($w->status, $w->status === 'active' ? 'success' : 'warning') . '</td><td>' . esc_html((string) $w->qr_count) . '</td><td>' . esc_html((string) $w->campaign_count) . '</td><td>' . esc_html((string) $w->scan_count) . '</td><td>' . esc_html($w->last_activity ?: '-') . '</td></tr>'; }
        return $html . '</tbody></table></div>' . $this->pager('/platform-admin/workspaces', $this->repo->workspaceCount($search), $search) . '</section>';
    }

    private function workspaceDetail(int $id): string {
        $w = $this->repo->workspaceDetail($id); if (!$w) { return $this->empty('Workspace not found', '/platform-admin/workspaces'); }
        $summary = $this->diagnosticSummary($id);
        $html = $this->header('Workspace: ' . $w->name, 'Usage, plan state, support diagnostics and safe actions.', '<a class="qrb-button" href="/platform-admin/workspaces">Workspaces</a>');
        $html .= $this->metrics([['Workspace ID',$w->id],['Plan',$w->plan_key],['Subscription',$w->subscription_status ?: '-'],['QR assets',$w->qr_count ?? 0],['Campaigns',$w->campaign_count ?? 0],['Scans',$w->scan_count ?? 0]]);
        $html .= '<div class="qrb-dashboard-grid"><section class="qrb-card"><h2>Overview</h2><p><strong>Owner:</strong> ' . esc_html($w->owner_email ?: '-') . '</p><p><strong>Status:</strong> ' . esc_html($w->status) . '</p><p><strong>Timezone:</strong> ' . esc_html($w->timezone ?: '-') . '</p><p><strong>Onboarding:</strong> ' . esc_html($w->onboarding_status ?: '-') . '</p></section><section class="qrb-card"><h2>Support actions</h2><div class="qrb-actions">' . ($w->status === 'active' ? $this->postButton('Suspend workspace', 'suspend_workspace', ['workspace_id' => $id, 'confirm_required' => 1], true) : $this->postButton('Reactivate workspace', 'reactivate_workspace', ['workspace_id' => $id])) . $this->testPlanForm($id, $w->plan_key) . $this->postButton('Generate diagnostics snapshot', 'snapshot', ['workspace_id' => $id]) . '</div></section></div>';
        return $html . '<section class="qrb-card"><h2>Sanitised support summary</h2><textarea readonly rows="12">' . esc_textarea(wp_json_encode($summary, JSON_PRETTY_PRINT)) . '</textarea></section>';
    }

    private function subscriptions(): string {
        $status = sanitize_key((string) ($_GET['status'] ?? '')); $rows = $this->repo->subscriptions($status, $this->pageNo()); $counts = $this->repo->subscriptionCounts();
        $html = $this->header('Subscriptions', 'Inspect local subscription state and Stripe identifiers.', $this->exportLink('subscriptions')) . $this->metrics([['Active',$counts['active']],['Trialing',$counts['trialing']],['Past due',$counts['past_due']],['Incomplete',$counts['incomplete']],['Free workspaces',$counts['free_plan_workspaces']]]);
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Workspace</th><th>User</th><th>Plan</th><th>Status</th><th>Stripe customer</th><th>Stripe subscription</th><th>Period end</th><th>Updated</th></tr></thead><tbody>';
        foreach ($rows as $s) { $html .= '<tr><td><a href="' . esc_url(home_url('/platform-admin/workspaces/' . $s->workspace_id)) . '">' . esc_html($s->workspace_name ?: ('Workspace #' . $s->workspace_id)) . '</a></td><td>' . esc_html($s->user_email ?: '-') . '</td><td>' . esc_html($s->plan_key) . '</td><td>' . esc_html($s->status) . '</td><td>' . esc_html($this->shortId($s->stripe_customer_id)) . '</td><td>' . esc_html($this->shortId($s->stripe_subscription_id)) . '</td><td>' . esc_html($s->current_period_end ?: '-') . '</td><td>' . esc_html($s->updated_at) . '</td></tr>'; }
        return $html . '</tbody></table></div></section>';
    }

    private function qrs(): string {
        $search = $this->search(); $rows = $this->repo->qrs($search, $this->pageNo());
        $html = $this->header('QR Inspector', 'Global QR search and support inspection.', $this->exportLink('qrs')) . $this->searchForm('/platform-admin/qrs', $search);
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>QR</th><th>Type</th><th>Status</th><th>Campaign</th><th>Scans</th><th>Destination</th><th>Updated</th></tr></thead><tbody>';
        foreach ($rows as $qr) { $html .= '<tr><td><a href="' . esc_url(home_url('/platform-admin/qrs/' . $qr->id)) . '">' . esc_html($qr->name) . '</a><br><small>' . esc_html($qr->shortcode) . '</small></td><td>' . esc_html($qr->type) . '</td><td>' . esc_html($qr->effectiveStatus()) . '</td><td>' . esc_html($qr->campaignName ?: '-') . '</td><td>' . esc_html((string) $qr->scanCount) . '</td><td>' . esc_html($qr->destinationUrl ?: $qr->staticPayload ?: '-') . '</td><td>' . esc_html($qr->updatedAt) . '</td></tr>'; }
        return $html . '</tbody></table></div>' . $this->pager('/platform-admin/qrs', $this->repo->qrCount($search), $search) . '</section>';
    }

    private function qrDetail(int $id): string {
        $qr = $this->repo->qrDetail($id); if (!$qr) { return $this->empty('QR code not found', '/platform-admin/qrs'); }
        $payload = $this->payloads->payloadForQrCode($qr);
        try { $preview = '<img class="qrb-preview" src="' . esc_attr($this->generator->generatePngDataUri($payload, 220, QRDesignSettings::fromQrCode($qr))) . '" alt="">'; } catch (\Throwable $e) { $preview = '<p>Preview unavailable.</p>'; }
        $rules = $this->repo->destinationRulesForQr($qr->id, $qr->workspaceId);
        $resolution = $this->platformResolutionSummary($qr, $rules);
        $html = $this->header('QR: ' . $qr->name, 'Identity, design, analytics and redirect diagnostics.', '<a class="qrb-button" href="/platform-admin/qrs">QR Inspector</a>');
        $html .= '<div class="qrb-card qrb-detail">' . $preview . '<div><p><strong>ID:</strong> ' . esc_html((string) $qr->id) . '</p><p><strong>Shortcode:</strong> ' . esc_html($qr->shortcode) . '</p><p><strong>Type:</strong> ' . esc_html($qr->type) . '</p><p><strong>Status:</strong> ' . esc_html($qr->effectiveStatus()) . '</p><p><strong>Tracking URL:</strong> ' . esc_html($qr->isTrackable() ? $this->generator->trackingUrl($qr->shortcode) : 'Static QR') . '</p></div></div>';
        $html .= $this->metrics([['Scans',$qr->scanCount],['Last scan',$qr->lastScan ?: '-'],['Resolver result',$resolution['result']],['Reason',$resolution['reason']]]);
        $html .= '<div class="qrb-dashboard-grid"><section class="qrb-card"><h2>Current behaviour</h2><p><strong>Encoded content:</strong> ' . esc_html($payload) . '</p><p><strong>Destination:</strong> ' . esc_html($resolution['destination']) . '</p><p><strong>Fallback:</strong> ' . esc_html($qr->fallbackUrl ?: '-') . '</p><p><strong>Expiry:</strong> ' . esc_html($qr->expiresAt ?: '-') . '</p></section><section class="qrb-card"><h2>Design</h2><p>' . esc_html($qr->foregroundColor . ' on ' . $qr->backgroundColor) . '</p><p>Dots: ' . esc_html($qr->dotStyle) . '; Finder: ' . esc_html($qr->finderStyle) . '; Error correction: ' . esc_html($qr->errorCorrection) . '</p><p>Logo: ' . esc_html($qr->logoAttachmentId ? 'Yes' : 'No') . '</p></section></div>';
        if ($qr->isTrackable()) {
            $warnings = $this->conflicts->analyze($qr, $rules);
            $html .= '<section class="qrb-card"><h2>Smart Destination rules</h2><p><strong>Matched rule:</strong> ' . esc_html($resolution['matched_rule'] ?: 'None') . '</p><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Priority</th><th>Name</th><th>Status</th><th>Conditions</th><th>Destination</th><th>Warnings</th></tr></thead><tbody>';
            foreach ($rules as $rule) { $ruleWarnings = array_filter($warnings, static fn(array $warning): bool => $warning['rule_id'] === $rule->id); $html .= '<tr><td>' . esc_html((string) $rule->priority) . '</td><td>' . esc_html($rule->name) . ($resolution['matched_rule_id'] === $rule->id ? '<br>' . $this->badge('Currently matching', 'success') : '') . '</td><td>' . esc_html($rule->status) . '</td><td>' . esc_html($this->conditionFormatter->format($rule)) . '</td><td>' . esc_html($rule->destinationUrl) . '</td><td>' . esc_html(implode(' ', array_column($ruleWarnings, 'message')) ?: '—') . '</td></tr>'; }
            if (!$rules) { $html .= '<tr><td colspan="6">No Smart Destination rules.</td></tr>'; }
            $html .= '</tbody></table></div></section>';
        }
        $history = $this->repo->destinationHistoryForQr($qr->id, $qr->workspaceId);
        $html .= '<section class="qrb-card"><h2>Recent rule history</h2><ul class="qrb-activity-list">';
        foreach ($history as $event) { $html .= '<li><strong>' . esc_html(ucwords(str_replace('_', ' ', $event->change_type))) . '</strong><span>' . esc_html(($event->actor_name ?: 'System') . ' · ' . $event->changed_at) . '</span><small>' . esc_html(($event->previous_value ?: '—') . ' → ' . ($event->new_value ?: '—')) . '</small></li>'; }
        $html .= '</ul></section><section class="qrb-card"><h2>Recent scans</h2>' . $this->scanTable($this->repo->recentScansForQr($id)) . '</section>';
        return $html;
    }

    private function platformResolutionSummary($qr, array $rules): array {
        if (!$qr->isTrackable()) { return ['result'=>'Static payload','reason'=>'static','destination'=>$qr->staticPayload ?: '-','matched_rule'=>'','matched_rule_id'=>null]; }
        if ($qr->isExpired()) { return ['result'=>$qr->fallbackUrl ? 'Fallback redirect' : 'Message','reason'=>'expired','destination'=>$qr->fallbackUrl ?: 'Expired message','matched_rule'=>'','matched_rule_id'=>null]; }
        if ($qr->status === 'paused' || !$qr->active) { return ['result'=>$qr->fallbackUrl ? 'Fallback redirect' : 'Message','reason'=>'paused','destination'=>$qr->fallbackUrl ?: 'Paused message','matched_rule'=>'','matched_rule_id'=>null]; }
        $timestamp = current_time('timestamp', true);
        foreach ($rules as $rule) { if ($this->resolver->ruleMatches($rule, $timestamp)) { return ['result'=>'Redirect','reason'=>'smart_rule','destination'=>$rule->destinationUrl,'matched_rule'=>$rule->name,'matched_rule_id'=>$rule->id]; } }
        return $this->resolutionSummary($qr) + ['matched_rule'=>'','matched_rule_id'=>null];
    }

    private function campaigns(): string {
        $search = $this->search(); $rows = $this->repo->campaigns($search, $this->pageNo());
        $html = $this->header('Campaigns', 'Platform-wide campaign inspection for support.', $this->exportLink('campaigns')) . $this->searchForm('/platform-admin/campaigns', $search);
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Campaign</th><th>Workspace</th><th>Owner</th><th>Status</th><th>QRs</th><th>Scans</th><th>Last activity</th></tr></thead><tbody>';
        foreach ($rows as $c) { $html .= '<tr><td>' . esc_html($c->name) . '<br><small>' . esc_html($c->description ?: '') . '</small></td><td>' . esc_html($c->workspace_name ?: '-') . '</td><td>' . esc_html($c->owner_email ?: '-') . '</td><td>' . esc_html($c->status) . '</td><td>' . esc_html((string) $c->qr_count) . '</td><td>' . esc_html((string) $c->scan_count) . '</td><td>' . esc_html($c->last_activity ?: '-') . '</td></tr>'; }
        return $html . '</tbody></table></div></section>';
    }

    private function systemHealth(): string {
        $html = $this->header('System Health', 'Operational checks and suggested next actions.', '<a class="qrb-button" href="/platform-admin/system-health?rerun=1">Rerun checks</a>');
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Check</th><th>Status</th><th>Explanation</th><th>Next action</th></tr></thead><tbody>';
        foreach ($this->health->checks() as $check) { $html .= '<tr><td>' . esc_html($check[0]) . '</td><td>' . $this->badge($check[1], $check[1] === 'healthy' ? 'success' : ($check[1] === 'failure' ? 'danger' : 'warning')) . '</td><td>' . esc_html($check[2]) . '</td><td>' . esc_html($check[3]) . '</td></tr>'; }
        return $html . '</tbody></table></div><p class="qrb-help">Last checked: ' . esc_html(current_time('mysql')) . '</p></section>';
    }

    private function diagnostics(): string {
        $summary = $this->diagnosticSummary(absint($_GET['workspace_id'] ?? 0));
        return $this->header('Diagnostics', 'Sanitised platform and workspace support summary.') . '<section class="qrb-card"><form class="qrb-filterbar"><label>Workspace ID<input name="workspace_id" value="' . esc_attr((string) ($_GET['workspace_id'] ?? '')) . '"></label><button class="qrb-button qrb-button-primary">Load</button></form><textarea readonly rows="18">' . esc_textarea(wp_json_encode($summary, JSON_PRETTY_PRINT)) . '</textarea>' . $this->postButton('Save snapshot', 'snapshot', ['workspace_id' => absint($_GET['workspace_id'] ?? 0)]) . '</section>';
    }

    private function webhooks(): string {
        $status = sanitize_key((string) ($_GET['status'] ?? '')); $rows = $this->webhooks->list($status, $this->pageNo());
        $html = $this->header('Webhooks', 'Stripe webhook processing records and retry review.', $this->exportLink('webhooks'));
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Event</th><th>Status</th><th>Workspace</th><th>Received</th><th>Processed</th><th>Retries</th><th>Error</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $w) { $html .= '<tr><td>' . esc_html($w->event_type) . '<br><small>' . esc_html($w->event_id) . '</small></td><td>' . esc_html($w->status) . '</td><td>' . esc_html($w->workspace_name ?: '-') . '</td><td>' . esc_html($w->received_at) . '</td><td>' . esc_html($w->processed_at ?: '-') . '</td><td>' . esc_html((string) $w->retry_count) . '</td><td>' . esc_html($w->error_summary ?: '-') . '</td><td>' . ($w->status === 'failed' ? $this->postButton('Retry', 'retry_webhook', ['webhook_id' => $w->id, 'confirm_required' => 1], true) : '-') . '</td></tr>'; }
        return $html . '</tbody></table></div></section>';
    }

    private function errors(): string {
        $status = sanitize_key((string) ($_GET['status'] ?? '')); $search = $this->search(); $rows = $this->errors->list($status, $search, $this->pageNo());
        $html = $this->header('Errors', 'Grouped application errors with safe context.', $this->exportLink('errors')) . $this->searchForm('/platform-admin/errors', $search);
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Severity</th><th>Category</th><th>Message</th><th>Workspace</th><th>User</th><th>Count</th><th>Last seen</th><th>Status</th><th>Action</th></tr></thead><tbody>';
        foreach ($rows as $e) { $html .= '<tr><td>' . esc_html($e->severity) . '</td><td>' . esc_html($e->category) . '</td><td>' . esc_html($e->message_summary) . '</td><td>' . esc_html($e->workspace_name ?: '-') . '</td><td>' . esc_html($e->user_name ?: '-') . '</td><td>' . esc_html((string) $e->occurrence_count) . '</td><td>' . esc_html($e->last_seen_at) . '</td><td>' . esc_html($e->status) . '</td><td>' . $this->errorStatusForm($e) . '</td></tr>'; }
        return $html . '</tbody></table></div></section>';
    }

    private function auditLog(): string {
        $search = $this->search(); $rows = $this->audit->list($search, $this->pageNo());
        $html = $this->header('Audit Log', 'Append-only administrator and support actions.', $this->exportLink('audit-log')) . $this->searchForm('/platform-admin/audit-log', $search);
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>When</th><th>Event</th><th>Actor</th><th>Workspace</th><th>Entity</th><th>Description</th></tr></thead><tbody>';
        foreach ($rows as $a) { $html .= '<tr><td>' . esc_html($a->created_at) . '</td><td>' . esc_html($a->event_type) . '</td><td>' . esc_html($a->actor_name ?: $a->actor_type) . '</td><td>' . esc_html($a->workspace_id ?: '-') . '</td><td>' . esc_html(trim((string) $a->entity_type . ' #' . (string) $a->entity_id)) . '</td><td>' . esc_html($a->description ?: '-') . '</td></tr>'; }
        return $html . '</tbody></table></div></section>';
    }

    private function featureFlags(): string {
        $html = $this->header('Feature Flags', 'Operational toggles for experiments. These do not replace plan entitlements.');
        $html .= '<section class="qrb-card"><div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Flag</th><th>Description</th><th>Scope</th><th>State</th><th>Updated by</th><th>Action</th></tr></thead><tbody>';
        foreach ($this->flags->all() as $flag) { $html .= '<tr><td>' . esc_html($flag->label) . '<br><small>' . esc_html($flag->flag_key) . '</small></td><td>' . esc_html($flag->description ?: '-') . '</td><td>' . esc_html($flag->scope) . '</td><td>' . $this->badge($flag->current_state ? 'Enabled' : 'Disabled', $flag->current_state ? 'success' : 'warning') . '</td><td>' . esc_html($flag->updated_by_name ?: '-') . '</td><td>' . $this->featureFlagForm($flag) . '</td></tr>'; }
        return $html . '</tbody></table></div></section>';
    }

    private function productAnalytics(): string {
        $a = $this->repo->productAnalytics();
        return $this->header('Product Analytics', 'Aggregated usage of QR Buzz itself, not customer campaign reporting.', $this->exportLink('product-analytics')) . '<div class="qrb-dashboard-grid"><section class="qrb-card"><h2>QR types</h2>' . $this->breakdown($a['qr_types']) . '</section><section class="qrb-card"><h2>Dot styles</h2>' . $this->breakdown($a['dot_styles']) . '</section><section class="qrb-card"><h2>Finder styles</h2>' . $this->breakdown($a['finder_styles']) . '</section><section class="qrb-card"><h2>Feature usage</h2>' . $this->metrics([['Logos',$a['logos']],['Smart rules',$a['smart_destinations']],['Campaigns',$a['campaigns']]]) . '</section></div>';
    }

    private function settings(): string {
        $settings = get_option('qrbuzz_platform_settings', []);
        return $this->header('Settings', 'Operational preferences. Secrets stay in environment configuration.') . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_platform_admin', 'nonce', true, false) . '<input type="hidden" name="platform_action" value="save_settings"><label>Support email<input type="email" name="support_email" value="' . esc_attr((string) ($settings['support_email'] ?? get_option('admin_email'))) . '"></label><label>Environment label<input name="environment_label" value="' . esc_attr((string) ($settings['environment_label'] ?? $this->repo->environmentLabel())) . '"></label><label>Error retention days<input type="number" name="error_retention_days" value="' . esc_attr((string) ($settings['error_retention_days'] ?? 90)) . '"></label><label>Audit retention days<input type="number" name="audit_retention_days" value="' . esc_attr((string) ($settings['audit_retention_days'] ?? 365)) . '"></label><label>Maintenance banner<textarea name="maintenance_banner">' . esc_textarea((string) ($settings['maintenance_banner'] ?? '')) . '</textarea></label><button class="qrb-button qrb-button-primary">Save settings</button></form>';
    }

    private function export(string $view): void {
        check_admin_referer('qrbuzz_platform_export_' . $view);
        $this->audit->record('administrator_action', 'CSV export downloaded: ' . $view, ['entity_type' => 'export']);
        $filename = 'qr-buzz-' . sanitize_title($view) . '-' . gmdate('Ymd-His') . '.csv';
        nocache_headers(); header('Content-Type: text/csv; charset=utf-8'); header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        $rows = $this->exportRows($view);
        if ($rows) { fputcsv($out, array_keys((array) $rows[0])); foreach ($rows as $row) { fputcsv($out, array_map([$this, 'csvCell'], (array) $row)); } }
        fclose($out); exit;
    }

    private function exportRows(string $view): array {
        return match ($view) {
            'users' => $this->repo->users($this->search(), 1, 500),
            'workspaces' => $this->repo->workspaces($this->search(), 1, 500),
            'subscriptions' => $this->repo->subscriptions('', 1, 500),
            'qrs' => $this->repo->qrs($this->search(), 1, 500),
            'campaigns' => $this->repo->campaigns($this->search(), 1, 500),
            'webhooks' => $this->webhooks->list('', 1, 500),
            'errors' => $this->errors->list('', $this->search(), 1, 500),
            'audit-log' => $this->audit->list($this->search(), 1, 500),
            'product-analytics' => $this->productAnalyticsExportRows(),
            default => [],
        };
    }

    private function productAnalyticsExportRows(): array {
        $analytics = $this->repo->productAnalytics();
        $rows = [];
        foreach (['qr_types', 'dot_styles', 'finder_styles'] as $section) {
            foreach ($analytics[$section] as $row) { $rows[] = ['section' => $section, 'label' => $row->label, 'count' => $row->count]; }
        }
        foreach (['logos', 'smart_destinations', 'campaigns'] as $metric) { $rows[] = ['section' => 'feature_usage', 'label' => $metric, 'count' => $analytics[$metric]]; }
        return $rows;
    }

    private function diagnosticSummary(int $workspaceId = 0): array {
        $summary = $this->repo->summary();
        $workspace = $workspaceId > 0 ? $this->repo->workspaceDetail($workspaceId) : null;
        $entitlements = null;
        if ($workspace) { $entitlements = (new EntitlementService())->summary(); }
        return ['version' => QR_BUZZ_VERSION, 'schema_version' => get_option('qrbuzz_db_version'), 'design_schema_version' => get_option('qrbuzz_design_schema_version'), 'environment' => $this->repo->environmentLabel(), 'workspace' => $workspace ? ['id' => (int) $workspace->id, 'name' => (string) $workspace->name, 'status' => (string) $workspace->status, 'plan_key' => (string) $workspace->plan_key, 'subscription_status' => (string) ($workspace->subscription_status ?: ''), 'onboarding_status' => (string) $workspace->onboarding_status, 'qr_count' => (int) ($workspace->qr_count ?? 0), 'campaign_count' => (int) ($workspace->campaign_count ?? 0), 'scan_count' => (int) ($workspace->scan_count ?? 0)] : null, 'summary' => $summary, 'entitlements_note' => $entitlements ? 'Entitlement snapshot is based on the current resolved workspace service context.' : 'No workspace selected.'];
    }

    private function resolutionSummary($qr): array {
        if (!$qr->isTrackable()) { return ['result' => 'Static payload', 'reason' => 'static', 'destination' => $qr->staticPayload ?: '-']; }
        if ($qr->effectiveStatus() === 'expired') { return ['result' => $qr->fallbackUrl ? 'Fallback redirect' : 'Message', 'reason' => 'expired', 'destination' => $qr->fallbackUrl ?: 'Expired message']; }
        if ($qr->status === 'paused') { return ['result' => $qr->fallbackUrl ? 'Fallback redirect' : 'Message', 'reason' => 'paused', 'destination' => $qr->fallbackUrl ?: 'Paused message']; }
        return ['result' => 'Redirect', 'reason' => 'primary', 'destination' => $qr->destinationUrl];
    }

    private function attentionList(): string { $items = $this->repo->attention(); if (!$items) { return '<p>No immediate issues detected.</p>'; } $html = '<ul class="qrb-activity-list">'; foreach ($items as [$title, $body, $url]) { $html .= '<li><span><strong>' . esc_html($title) . '</strong><br><small>' . esc_html($body) . '</small></span><a class="qrb-button" href="' . esc_url(home_url($url)) . '">Open</a></li>'; } return $html . '</ul>'; }
    private function activityList(array $rows): string { if (!$rows) { return '<p>No activity yet.</p>'; } $html = '<ul class="qrb-activity-list">'; foreach ($rows as $r) { $html .= '<li><span>' . esc_html(ucwords(str_replace('_', ' ', (string) $r->title))) . '<br><small>' . esc_html((string) $r->object_type . ' #' . (string) $r->object_id) . '</small></span><span>' . esc_html((string) $r->happened_at) . '</span></li>'; } return $html . '</ul>'; }
    private function scanTable(array $rows): string { if (!$rows) { return '<p>No scans yet.</p>'; } $html = '<div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>When</th><th>Reason</th><th>Status</th><th>Referrer</th><th>User agent</th></tr></thead><tbody>'; foreach ($rows as $r) { $html .= '<tr><td>' . esc_html($r->scanned_at) . '</td><td>' . esc_html($r->resolution_reason ?: '-') . '</td><td>' . esc_html($r->scan_status ?: '-') . '</td><td>' . esc_html($r->referrer ?: 'Direct / unknown') . '</td><td>' . esc_html($r->user_agent_summary ?: '-') . '</td></tr>'; } return $html . '</tbody></table></div>'; }
    private function breakdown(array $rows): string { if (!$rows) { return '<p>No data yet.</p>'; } $html = '<table class="qrb-table"><tbody>'; foreach ($rows as $row) { $html .= '<tr><td>' . esc_html((string) $row->label) . '</td><td>' . esc_html((string) $row->count) . '</td></tr>'; } return $html . '</tbody></table>'; }
    private function metrics(array $metrics): string { $html = '<div class="qrb-metrics">'; foreach ($metrics as $m) { $html .= '<div class="qrb-card qrb-metric"><span>' . esc_html((string) $m[0]) . '</span><strong>' . esc_html((string) $m[1]) . '</strong>' . (isset($m[2]) ? '<small>' . esc_html((string) $m[2]) . '</small>' : '') . '</div>'; } return $html . '</div>'; }
    private function header(string $title, string $description, string $actions = ''): string { return $this->notice() . '<div class="qrb-page-header"><div><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></div><div class="qrb-actions">' . $actions . '</div></div>'; }
    private function notice(): string { if (empty($_GET['notice'])) { return ''; } return '<div class="qrb-toast" role="status">' . (sanitize_key((string) $_GET['notice']) === 'saved' ? 'Changes saved.' : 'Action could not be completed.') . '</div>'; }
    private function searchForm(string $url, string $search): string { return '<form class="qrb-filterbar" method="get" action="' . esc_url(home_url($url)) . '"><label>Search<input name="s" value="' . esc_attr($search) . '" placeholder="Search"></label><button class="qrb-button qrb-button-primary">Search</button><a class="qrb-button" href="' . esc_url(home_url($url)) . '">Clear</a></form>'; }
    private function pager(string $url, int $total, string $search = ''): string { $page = $this->pageNo(); $pages = max(1, (int) ceil($total / 25)); if ($pages <= 1) { return ''; } $base = home_url($url); $prev = max(1, $page - 1); $next = min($pages, $page + 1); return '<div class="qrb-actions"><a class="qrb-button" href="' . esc_url(add_query_arg(['paged' => $prev, 's' => $search], $base)) . '">Previous</a><span class="qrb-help">Page ' . esc_html((string) $page) . ' of ' . esc_html((string) $pages) . '</span><a class="qrb-button" href="' . esc_url(add_query_arg(['paged' => $next, 's' => $search], $base)) . '">Next</a></div>'; }
    private function exportLink(string $view): string { return '<a class="qrb-button" href="' . esc_url(wp_nonce_url(add_query_arg('export', $view, home_url('/platform-admin/' . ($view === 'audit-log' ? 'audit-log' : $view))), 'qrbuzz_platform_export_' . $view)) . '">Export CSV</a>'; }
    private function postButton(string $label, string $action, array $data = [], bool $confirm = false): string { $html = '<form class="qrb-inline-form" method="post">' . wp_nonce_field('qrbuzz_platform_admin', 'nonce', true, false) . '<input type="hidden" name="platform_action" value="' . esc_attr($action) . '">'; foreach ($data as $key => $value) { if ($key === 'confirm_required') { continue; } $html .= '<input type="hidden" name="' . esc_attr((string) $key) . '" value="' . esc_attr((string) $value) . '">'; } if ($confirm) { $html .= '<label class="qrb-confirm"><input type="checkbox" name="confirm" value="1" required> Confirm</label>'; } return $html . '<button class="qrb-button">' . esc_html($label) . '</button></form>'; }
    private function testPlanForm(int $workspaceId, string $current): string { $html = '<form class="qrb-inline-form" method="post">' . wp_nonce_field('qrbuzz_platform_admin', 'nonce', true, false) . '<input type="hidden" name="platform_action" value="assign_test_plan"><input type="hidden" name="workspace_id" value="' . esc_attr((string) $workspaceId) . '"><select name="plan_key">'; foreach (['free','pro','business'] as $plan) { $html .= '<option value="' . esc_attr($plan) . '" ' . selected($current, $plan, false) . '>' . esc_html(ucfirst($plan)) . '</option>'; } return $html . '</select><label class="qrb-confirm"><input type="checkbox" name="confirm" value="1" required> Confirm</label><button class="qrb-button">Assign test plan</button></form>'; }
    private function errorStatusForm($error): string { $html = '<form class="qrb-inline-form" method="post">' . wp_nonce_field('qrbuzz_platform_admin', 'nonce', true, false) . '<input type="hidden" name="platform_action" value="error_status"><input type="hidden" name="error_id" value="' . esc_attr((string) $error->id) . '"><select name="status">'; foreach (['new','investigating','resolved','ignored'] as $status) { $html .= '<option value="' . esc_attr($status) . '" ' . selected($error->status, $status, false) . '>' . esc_html(ucfirst($status)) . '</option>'; } return $html . '</select><button class="qrb-button">Update</button></form>'; }
    private function featureFlagForm($flag): string { return '<form class="qrb-inline-form" method="post">' . wp_nonce_field('qrbuzz_platform_admin', 'nonce', true, false) . '<input type="hidden" name="platform_action" value="feature_flag"><input type="hidden" name="flag_id" value="' . esc_attr((string) $flag->id) . '"><label><input type="checkbox" name="enabled" value="1" ' . checked((bool) $flag->current_state, true, false) . '> Enabled</label><button class="qrb-button">Save</button></form>'; }
    private function badge(string $label, string $kind): string { return '<span class="qrb-badge qrb-badge-' . esc_attr($kind) . '">' . esc_html(ucfirst($label)) . '</span>'; }
    private function empty(string $message, string $back): string { return '<section class="qrb-empty"><h1>' . esc_html($message) . '</h1><a class="qrb-button" href="' . esc_url(home_url($back)) . '">Back</a></section>'; }
    private function settingsFromPost(): array { return ['support_email' => sanitize_email((string) ($_POST['support_email'] ?? '')), 'environment_label' => sanitize_key((string) ($_POST['environment_label'] ?? 'production')), 'error_retention_days' => max(1, absint($_POST['error_retention_days'] ?? 90)), 'audit_retention_days' => max(1, absint($_POST['audit_retention_days'] ?? 365)), 'maintenance_banner' => sanitize_textarea_field((string) ($_POST['maintenance_banner'] ?? ''))]; }
    private function workspaceForUser(int $userId) { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT w.* FROM ' . Schema::workspacesTable() . ' w INNER JOIN ' . Schema::membershipsTable() . ' m ON m.workspace_id = w.id WHERE m.user_id = %d ORDER BY m.id ASC LIMIT 1', $userId)); }
    private function shortId($value): string { $value = (string) $value; return $value === '' ? '-' : substr($value, 0, 10) . (strlen($value) > 10 ? '...' : ''); }
    private function csvCell($value): string { $value = is_scalar($value) ? (string) $value : wp_json_encode($value); return preg_match('/^[=+\-@]/', $value) ? "'" . $value : $value; }
    private function confirmed(): bool { return !empty($_POST['confirm']); }
    private function search(): string { return sanitize_text_field((string) ($_GET['s'] ?? '')); }
    private function pageNo(): int { return max(1, absint($_GET['paged'] ?? 1)); }
    private function canAccess(): bool { return is_user_logged_in() && current_user_can('qrbuzz_manage_platform'); }
    private function path(): string { return trim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/'); }
    private function page(string $title, string $content, int $status = 200): void { status_header($status); nocache_headers(); echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . ' · QR Buzz Platform</title>' . $this->styles() . '</head><body class="qrb-body qrb-platform"><a class="qrb-skip-link" href="#qrb-main">Skip to content</a><div class="qrb-shell"><header class="qrb-topbar"><a class="qrb-brand" href="/platform-admin/dashboard"><span class="qrb-brand-mark">QR</span><span>Platform Admin</span></a><div class="qrb-workspace"><span>Environment</span><strong>' . esc_html($this->repo->environmentLabel()) . '</strong></div><div class="qrb-topbar-actions"><span class="qrb-badge">v' . esc_html(QR_BUZZ_VERSION) . '</span><span class="qrb-badge">' . esc_html($this->stripeMode()) . '</span><a class="qrb-button" href="/app/dashboard">Workspace</a><a class="qrb-button" href="/logout">' . esc_html(wp_get_current_user()->display_name ?: 'Logout') . '</a></div></header><aside class="qrb-sidebar">' . $this->navigation() . '</aside><div class="qrb-app-frame"><main id="qrb-main" class="qrb-main">' . $content . '</main></div></div></body></html>'; exit; }
    private function unauthorised(): void { status_header(is_user_logged_in() ? 403 : 401); echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Platform access required</title>' . $this->styles() . '</head><body class="qrb-body"><main class="qrb-public"><section class="qrb-card"><h1>Platform access required</h1><p>Your account does not currently have QR Buzz platform administration access.</p><a class="qrb-button qrb-button-primary" href="' . esc_url(is_user_logged_in() ? home_url('/app/dashboard') : home_url('/login')) . '">' . esc_html(is_user_logged_in() ? 'Return to workspace' : 'Log in') . '</a></section></main></body></html>'; exit; }
    private function navigation(): string { $items = ['Overview'=>'dashboard','Users'=>'users','Workspaces'=>'workspaces','Subscriptions'=>'subscriptions','QR Inspector'=>'qrs','Campaigns'=>'campaigns','Product Analytics'=>'product-analytics','System Health'=>'system-health','Diagnostics'=>'diagnostics','Webhooks'=>'webhooks','Errors'=>'errors','Audit Log'=>'audit-log','Feature Flags'=>'feature-flags','Settings'=>'settings']; $path = $this->path(); $html = '<nav class="qrb-sidebar-nav">'; foreach ($items as $label => $slug) { $url = '/platform-admin/' . $slug; $active = $path === trim($url, '/') || str_starts_with($path, trim($url, '/') . '/'); $html .= '<a class="qrb-nav-link ' . ($active ? 'is-active' : '') . '" href="' . esc_url(home_url($url)) . '">' . esc_html($label) . '</a>'; } return $html . '</nav>'; }
    private function styles(): string { return '<style>' . $this->asset('assets/css/tokens.css') . $this->asset('assets/css/reset.css') . $this->asset('assets/css/base.css') . $this->asset('assets/css/typography.css') . $this->asset('assets/css/layout.css') . $this->asset('assets/css/components.css') . $this->asset('assets/css/forms.css') . $this->asset('assets/css/tables.css') . $this->asset('assets/css/utilities.css') . $this->asset('assets/css/pages/dashboard.css') . $this->asset('assets/css/pages/analytics.css') . $this->asset('assets/css/responsive.css') . '.qrb-platform .qrb-sidebar{background:transparent}.qrb-platform .qrb-brand-mark{background:#217a9b}.qrb-inline-form{display:inline-flex;align-items:center;gap:.5rem;flex-wrap:wrap}.qrb-inline-form select{min-height:40px}.qrb-confirm{display:inline-flex!important;grid-template-columns:none!important;align-items:center;gap:.35rem;font-size:12px}.qrb-card textarea[readonly]{width:100%;font-family:ui-monospace,Consolas,monospace}.qrb-table-wrap{overflow:auto}</style>'; }
    private function asset(string $path): string { $file = QR_BUZZ_PATH . str_replace('/', DIRECTORY_SEPARATOR, $path); return is_readable($file) ? (string) file_get_contents($file) : ''; }
    private function stripeMode(): string { return ((defined('QR_BUZZ_STRIPE_SECRET_KEY') && str_starts_with((string) QR_BUZZ_STRIPE_SECRET_KEY, 'sk_live_')) || str_starts_with((string) getenv('QR_BUZZ_STRIPE_SECRET_KEY'), 'sk_live_')) ? 'Stripe live' : 'Stripe test/local'; }
}
