<?php
namespace QRBuzz\App;

use QRBuzz\Account\AuthService;
use QRBuzz\Account\ProfileRepository;
use QRBuzz\Analytics\AnalyticsRepository;
use QRBuzz\Analytics\DateRange;
use QRBuzz\Analytics\PerQRAnalyticsService;
use QRBuzz\Billing\StripeService;
use QRBuzz\Billing\SubscriptionRepository;
use QRBuzz\Database\CampaignRepository;
use QRBuzz\Database\QRRepository;
use QRBuzz\Database\WorkspaceRepository;
use QRBuzz\Membership\EntitlementService;
use QRBuzz\Membership\PlanRegistry;
use QRBuzz\Models\QRCode;
use QRBuzz\QR\Design\BrandKitSettings;
use QRBuzz\QR\Design\QRDesignSettings;
use QRBuzz\QR\Design\QRThemeRegistry;
use QRBuzz\QR\QRGenerator;
use QRBuzz\QR\Types\QRPayloadService;
use QRBuzz\QR\Types\QRTypeRegistry;
use QRBuzz\Workspace\WorkspaceService;

class HostedAppController {

    private AuthService $auth;
    private ProfileRepository $profiles;
    private WorkspaceRepository $workspaceRepo;
    private WorkspaceService $workspaces;
    private EntitlementService $entitlements;
    private PlanRegistry $plans;
    private QRRepository $qrCodes;
    private CampaignRepository $campaigns;
    private QRPayloadService $payloads;
    private QRTypeRegistry $types;
    private BrandKitSettings $brandKit;
    private SubscriptionRepository $subscriptions;
    private StripeService $stripe;
    private QRGenerator $generator;
    private QRThemeRegistry $themes;
    private AnalyticsRepository $analyticsRepo;
    private PerQRAnalyticsService $perQrAnalytics;

    public function __construct() {
        $this->profiles = new ProfileRepository();
        $this->workspaceRepo = new WorkspaceRepository();
        $this->workspaces = new WorkspaceService($this->workspaceRepo);
        $this->plans = new PlanRegistry();
        $this->entitlements = new EntitlementService($this->workspaces, $this->plans);
        $this->auth = new AuthService($this->profiles, $this->workspaceRepo);
        $this->qrCodes = new QRRepository(null, null, $this->workspaces);
        $this->campaigns = new CampaignRepository($this->workspaces);
        $this->types = new QRTypeRegistry();
        $this->generator = new QRGenerator();
        $this->payloads = new QRPayloadService($this->types, $this->generator);
        $this->themes = new QRThemeRegistry();
        $this->brandKit = new BrandKitSettings($this->themes, $this->workspaces);
        $this->subscriptions = new SubscriptionRepository();
        $this->stripe = new StripeService($this->subscriptions, $this->workspaceRepo);
        $this->analyticsRepo = new AnalyticsRepository(null, $this->workspaces);
        $this->perQrAnalytics = new PerQRAnalyticsService(null, $this->workspaces);
    }

    public function init(): void { add_action('template_redirect', [$this, 'route'], 0); }

    public function route(): void {
        $path = $this->path();
        if (!in_array($path, ['pricing','register','login','forgot-password','verify-email','logout'], true) && !str_starts_with($path, 'app')) { return; }
        $this->handlePost($path);
        if ($path === 'logout') { wp_logout(); wp_safe_redirect(home_url('/login')); exit; }
        if ($path === 'pricing') { $this->page('Pricing', $this->pricing()); }
        if ($path === 'register') { if (is_user_logged_in()) { $this->redirectAfterLogin(); } $this->page('Create account', $this->registerForm()); }
        if ($path === 'login') { if (is_user_logged_in()) { $this->redirectAfterLogin(); } $this->page('Log in', $this->loginForm()); }
        if ($path === 'forgot-password') { $this->page('Password recovery', $this->passwordPage()); }
        if ($path === 'verify-email') { $this->verifyEmail(); }
        if (str_starts_with($path, 'app')) { $this->requireApp(); $this->appPage($path); }
    }

    private function handlePost(string $path): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
        if ($path === 'register') { $this->postRegister(); }
        if ($path === 'login') { $this->postLogin(); }
        if ($path === 'forgot-password') { $this->postPassword(); }
        if ($path === 'app/onboarding') { $this->postOnboarding(); }
        if ($path === 'app/qr/new') { $this->postQrStudio(); }
        if (preg_match('#^app/qr/(\d+)/studio$#', $path, $m)) { $this->postQrStudio((int) $m[1]); }
        if ($path === 'app/campaigns') { $this->postCampaign(); }
        if (preg_match('#^app/campaigns/(\d+)$#', $path, $m)) { $this->postCampaign((int) $m[1]); }
        if ($path === 'app/settings/account') { $this->postAccount(); }
        if ($path === 'app/settings/workspace') { $this->postWorkspace(); }
    }

    private function postRegister(): void {
        check_admin_referer('qrbuzz_register', 'nonce');
        if ($this->rateLimited('register')) { $this->redirect('/register?error=rate'); }
        $user = $this->auth->register($_POST);
        if (is_wp_error($user)) { $this->redirect('/register?error=' . rawurlencode($user->get_error_code())); }
        $this->redirect('/app/onboarding');
    }

    private function postLogin(): void {
        check_admin_referer('qrbuzz_login', 'nonce');
        if ($this->rateLimited('login')) { $this->redirect('/login?error=rate'); }
        $user = $this->auth->login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''), !empty($_POST['remember']));
        if (is_wp_error($user)) { $this->redirect('/login?error=failed'); }
        $this->redirectAfterLogin();
    }

    private function postPassword(): void {
        $mode = sanitize_key((string) ($_POST['mode'] ?? 'request'));
        if ($mode === 'reset') {
            check_admin_referer('qrbuzz_password_reset', 'nonce');
            $user = check_password_reset_key((string) ($_POST['key'] ?? ''), (string) ($_POST['login'] ?? ''));
            if (is_wp_error($user) || empty($_POST['password']) || $_POST['password'] !== ($_POST['password_confirm'] ?? '')) { $this->redirect('/forgot-password?error=reset'); }
            reset_password($user, (string) $_POST['password']);
            $this->redirect('/login?reset=1');
        }
        check_admin_referer('qrbuzz_password_request', 'nonce');
        $login = sanitize_text_field((string) ($_POST['login'] ?? ''));
        $user = get_user_by('email', $login) ?: get_user_by('login', $login);
        if ($user) { $key = get_password_reset_key($user); if (!is_wp_error($key)) { wp_mail($user->user_email, 'Reset your QR Buzz password', 'Reset your password: ' . add_query_arg(['action' => 'reset', 'key' => $key, 'login' => rawurlencode($user->user_login)], home_url('/forgot-password'))); } }
        $this->redirect('/forgot-password?sent=1');
    }

    private function postOnboarding(): void {
        check_admin_referer('qrbuzz_onboarding', 'nonce');
        $workspace = $this->workspaces->current();
        $action = sanitize_key((string) ($_POST['onboarding_action'] ?? ''));
        if ($action === 'plan') {
            $plan = sanitize_key((string) ($_POST['plan_key'] ?? 'free'));
            if (!array_key_exists($plan, $this->plans->plans())) { $plan = 'free'; }
            if ($plan === 'free' || !$this->stripe->configured()) {
                $this->workspaceRepo->updatePlan($workspace->id, $plan);
                $this->subscriptions->upsert($workspace->id, ['user_id' => get_current_user_id(), 'plan_key' => $plan, 'status' => $plan === 'free' ? 'free' : 'active']);
                $this->workspaceRepo->updateOnboarding($workspace->id, 'pending', 'workspace');
                $this->profiles->updateOnboarding(get_current_user_id(), 'pending', 'workspace');
                $this->redirect('/app/onboarding?step=workspace' . ($plan === 'free' ? '' : '&billing=prototype'));
            }
            $checkout = $this->stripe->createCheckoutSession($workspace->id, get_current_user_id(), $plan);
            if (!is_wp_error($checkout)) { wp_safe_redirect($checkout); exit; }
            $this->redirect('/app/onboarding?step=plan&error=stripe_config');
        }
        if ($action === 'workspace') { $this->workspaceRepo->updateSettings($workspace->id, ['name' => sanitize_text_field((string) ($_POST['workspace_name'] ?? $workspace->name)), 'website_url' => esc_url_raw((string) ($_POST['website_url'] ?? '')), 'intended_use' => sanitize_key((string) ($_POST['intended_use'] ?? '')), 'timezone' => sanitize_text_field((string) ($_POST['timezone'] ?? wp_timezone_string()))]); $this->workspaceRepo->updateOnboarding($workspace->id, 'pending', 'first_qr'); $this->profiles->updateOnboarding(get_current_user_id(), 'pending', 'first_qr'); $this->redirect('/app/onboarding?step=first_qr'); }
        if ($action === 'complete') { $this->completeOnboarding(); $this->redirect('/app/dashboard?welcome=1'); }
    }

    private function postQrStudio(int $id = 0): void {
        check_admin_referer('qrbuzz_app_qr', 'nonce');
        if (!$this->entitlements->canCreate('qr_assets') && $id === 0) { $this->redirect('/app/qr/new?error=limit'); }
        $existing = $id > 0 ? $this->qrCodes->find($id) : null;
        if ($id > 0 && !$existing) { $this->redirect('/app/library?error=missing'); }
        $type = $this->types->normalize(sanitize_key((string) ($_POST['type'] ?? ($existing ? $existing->type : 'dynamic_url'))));
        if ($id === 0 && $type === 'dynamic_url' && !$this->entitlements->canCreate('dynamic_qr_assets')) { $this->redirect('/app/qr/new?error=dynamic_limit'); }
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        $payload = $this->payloadFromPost($type);
        $staticPayload = $this->payloads->build($type, $payload);
        if ($name === '' || !$this->payloads->isPayloadValid($type, $staticPayload)) { $this->redirect($id > 0 ? '/app/qr/' . $id . '/studio?error=invalid' : '/app/qr/new?type=' . rawurlencode($type) . '&studio=1&error=invalid'); }
        $status = in_array((string) ($_POST['status'] ?? 'active'), ['active', 'paused'], true) ? (string) $_POST['status'] : 'active';
        $destination = $type === 'dynamic_url' ? esc_url_raw((string) ($payload['destination_url'] ?? '')) : ($type === 'static_url' ? esc_url_raw((string) ($payload['url'] ?? '')) : '');
        $design = QRDesignSettings::fromPost($_POST, $existing ? QRDesignSettings::fromQrCode($existing) : $this->newDesignDefaults());
        if (!$this->entitlements->allows('logo_embedding')) { $design->logoAttachmentId = 0; }
        $settings = ['type' => $type, 'payload_data' => $payload, 'static_payload' => $staticPayload, 'status' => $status, 'fallback_url' => $this->optionalUrl('fallback_url'), 'expires_at' => $this->optionalDateTime('expires_at'), 'scheduled_url' => $this->optionalUrl('scheduled_url'), 'scheduled_start_at' => $this->optionalDateTime('scheduled_start_at'), 'scheduled_end_at' => $this->optionalDateTime('scheduled_end_at'), 'campaign_id' => absint($_POST['campaign_id'] ?? 0), 'design' => $design->toArray()];
        if ($id > 0) { $this->qrCodes->update($id, $name, $destination, $status, $settings, get_current_user_id()); $this->redirect('/app/qr/' . $id . '?saved=1'); }
        $newId = $this->qrCodes->create($name, $destination, $settings);
        $this->completeOnboarding();
        $this->redirect('/app/qr/' . $newId . '?created=1');
    }

    private function postCampaign(int $id = 0): void {
        check_admin_referer('qrbuzz_app_campaign', 'nonce');
        if (!$this->entitlements->canCreate('campaigns') && $id === 0) { $this->redirect('/app/campaigns?error=limit'); }
        $name = sanitize_text_field((string) ($_POST['name'] ?? ''));
        if (!$name) { $this->redirect($id > 0 ? '/app/campaigns/' . $id . '?error=invalid' : '/app/campaigns?error=invalid'); }
        $description = sanitize_textarea_field((string) ($_POST['description'] ?? ''));
        if ($id > 0) { $status = sanitize_key((string) ($_POST['status'] ?? 'active')); $this->campaigns->update($id, $name, $description, $status); $this->redirect('/app/campaigns/' . $id . '?saved=1'); }
        $newId = $this->campaigns->create($name, $description);
        $this->redirect('/app/campaigns/' . $newId . '?created=1');
    }

    private function postAccount(): void {
        check_admin_referer('qrbuzz_app_account', 'nonce');
        $userId = get_current_user_id();
        $user = wp_get_current_user();
        $email = sanitize_email((string) ($_POST['email'] ?? $user->user_email));
        if (!is_email($email)) { $this->redirect('/app/settings/account?error=email'); }
        $existing = email_exists($email);
        if ($existing && (int) $existing !== $userId) { $this->redirect('/app/settings/account?error=email_taken'); }
        $emailChanged = strtolower($email) !== strtolower((string) $user->user_email);
        $result = wp_update_user(['ID' => $userId, 'user_email' => $email, 'first_name' => sanitize_text_field((string) ($_POST['first_name'] ?? '')), 'last_name' => sanitize_text_field((string) ($_POST['last_name'] ?? ''))]);
        if (is_wp_error($result)) { $this->redirect('/app/settings/account?error=save'); }
        if ($emailChanged) { $this->profiles->markEmailUnverified($userId); $this->auth->sendVerification($userId); }
        if (!empty($_POST['password'])) { if ($_POST['password'] !== ($_POST['password_confirm'] ?? '') || !$this->auth->strongPassword((string) $_POST['password'])) { $this->redirect('/app/settings/account?error=password'); } wp_set_password((string) $_POST['password'], $userId); wp_set_auth_cookie($userId); }
        $this->redirect('/app/settings/account?saved=1' . ($emailChanged ? '&verify=1' : ''));
    }

    private function postWorkspace(): void { check_admin_referer('qrbuzz_app_workspace', 'nonce'); if (!$this->workspaces->isOwner()) { $this->redirect('/app/settings/workspace?error=permission'); } $this->workspaceRepo->updateSettings($this->workspaces->id(), ['name' => sanitize_text_field((string) ($_POST['name'] ?? '')), 'website_url' => esc_url_raw((string) ($_POST['website_url'] ?? '')), 'timezone' => sanitize_text_field((string) ($_POST['timezone'] ?? wp_timezone_string())), 'intended_use' => sanitize_key((string) ($_POST['intended_use'] ?? ''))]); $this->redirect('/app/settings/workspace?saved=1'); }

    private function appPage(string $path): void {
        $workspace = $this->workspaces->current();
        $allowedDuringOnboarding = ['app/onboarding', 'app/qr/new'];
        if (!$workspace->onboardingComplete() && !in_array($path, $allowedDuringOnboarding, true) && !preg_match('#^app/qr/(\d+)$#', $path) && !preg_match('#^app/qr/(\d+)/studio$#', $path)) { $this->redirect('/app/onboarding'); }
        if ($path === 'app' || $path === 'app/dashboard') { $this->app('Dashboard', $this->dashboard()); }
        if ($path === 'app/onboarding') { $this->page('Onboarding', $this->onboarding()); }
        if ($path === 'app/library') { $this->app('Library', $this->library()); }
        if ($path === 'app/qr/new') { $this->app('New QR', $this->qrForm()); }
        if (preg_match('#^app/qr/(\d+)/studio$#', $path, $m)) { $this->app('QR Studio', $this->qrStudio((int) $m[1])); }
        if (preg_match('#^app/qr/(\d+)/download/(png|svg)$#', $path, $m)) { $this->downloadQr((int) $m[1], (string) $m[2]); }
        if (preg_match('#^app/qr/(\d+)$#', $path, $m)) { $this->app('QR detail', $this->qrDetail((int) $m[1])); }
        if ($path === 'app/campaigns') { $this->app('Campaigns', $this->campaignPage()); }
        if (preg_match('#^app/campaigns/(\d+)$#', $path, $m)) { $this->app('Edit Campaign', $this->campaignEdit((int) $m[1])); }
        if ($path === 'app/analytics') { $this->app('Analytics', $this->analytics()); }
        if ($path === 'app/settings' || $path === 'app/settings/account') { $this->app('Account settings', $this->accountSettings()); }
        if ($path === 'app/settings/workspace') { $this->app('Workspace settings', $this->workspaceSettings()); }
        if ($path === 'app/settings/billing') { $this->app('Billing', $this->billingSettings()); }
        $this->notFound();
    }

    private function dashboard(): string { $s = $this->qrCodes->dashboardSummary(); $e = $this->entitlements->summary(); return $this->hero('Welcome to ' . esc_html($this->workspaces->current()->name), '<a class="qrb-button qrb-button-primary" href="' . esc_url(home_url('/app/qr/new')) . '">Create QR</a>') . $this->metrics([['QR assets',$s['total_qr_codes']],['Total scans',$s['total_scans']],['Campaigns',count($this->campaigns->active())],['Plan',$e['plan']['label']]]) . '<section class="qrb-card"><h2>Quick actions</h2><div class="qrb-actions"><a class="qrb-button" href="/app/library">QR Library</a><a class="qrb-button" href="/app/qr/new?type=dynamic_url&studio=1">Dynamic QR</a><a class="qrb-button" href="/app/qr/new?type=static_url&studio=1">Static QR</a><a class="qrb-button" href="/app/qr/new?type=wifi&studio=1">WiFi QR</a><a class="qrb-button" href="/app/campaigns">New Campaign</a></div></section>' . $this->usageCard(); }
    private function library(): string { $rows = ''; foreach ($this->qrCodes->queryWithScanCounts('', 1, 50) as $qr) { $rows .= '<tr><td><a href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '">' . esc_html($qr->name) . '</a></td><td>' . esc_html($this->types->label($qr->type)) . '</td><td>' . esc_html((string) $qr->scanCount) . '</td><td>' . esc_html($qr->createdAt) . '</td></tr>'; } return '<div class="qrb-page-head"><h1>Library</h1><a class="qrb-button qrb-button-primary" href="/app/qr/new">New QR</a></div><table class="qrb-table"><thead><tr><th>Name</th><th>Type</th><th>Scans</th><th>Created</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4">No QR assets yet.</td></tr>') . '</tbody></table>'; }

    private function qrForm(): string {
        if (!empty($_GET['studio'])) { return $this->qrStudio(0); }
        $html = '<div class="qrb-page-head"><h1>Choose QR type</h1><a class="qrb-button" href="/app/dashboard">Dashboard</a></div>';
        if (!empty($_GET['error'])) { $html .= '<p class="qrb-alert">That QR could not be created. Please check the details and try again.</p>'; }
        $html .= '<section class="qrb-type-grid">';
        foreach ($this->types->all() as $type => $meta) { $html .= '<article class="qrb-card qrb-type-card"><h2>' . esc_html($meta['label']) . '</h2><p>' . esc_html($meta['description']) . '</p><a class="qrb-button qrb-button-primary" href="' . esc_url(home_url('/app/qr/new?type=' . rawurlencode($type) . '&studio=1')) . '">Open in QR Studio</a></article>'; }
        return $html . '</section>';
    }

    private function qrStudio(int $id): string {
        $qr = $id > 0 ? $this->qrCodes->find($id) : null;
        if ($id > 0 && !$qr) { return '<h1>QR not found</h1>'; }
        $type = $qr ? $qr->type : $this->types->normalize(sanitize_key((string) ($_GET['type'] ?? 'dynamic_url')));
        $payload = $qr ? $qr->payloadData : [];
        if ($qr && !$payload) { $payload = ['destination_url' => $qr->destinationUrl, 'url' => $qr->destinationUrl]; }
        $design = $qr ? QRDesignSettings::fromQrCode($qr) : $this->newDesignDefaults();
        $title = $qr ? 'Edit QR' : 'New QR';
        $action = $qr ? home_url('/app/qr/' . $qr->id . '/studio') : home_url('/app/qr/new');
        return '<div class="qrb-page-head"><h1>QR Studio</h1><a class="qrb-button" href="/app/qr/new">Change type</a></div>' . (!empty($_GET['error']) ? '<p class="qrb-alert">Please check the QR details and try again.</p>' : '') . '<div class="qrb-studio"><aside class="qrb-card qrb-studio-preview"><h2>Preview</h2>' . $this->studioPreview($qr, $type, $payload, $design) . '</aside><form class="qrb-card qrb-form qrb-studio-form" method="post" action="' . esc_url($action) . '">' . wp_nonce_field('qrbuzz_app_qr', 'nonce', true, false) . '<input type="hidden" name="qr_id" value="' . esc_attr($qr ? $qr->id : 0) . '"><h2>' . esc_html($title) . '</h2>' . $this->studioContentFields($qr, $type, $payload) . $this->studioDesignFields($design) . '<button class="qrb-button qrb-button-primary">' . esc_html($qr ? 'Update QR Code' : 'Create QR Code') . '</button></form></div>';
    }

    private function studioContentFields(?QRCode $qr, string $type, array $payload): string {
        $html = '<fieldset><legend>Content</legend><label>Name<input name="name" value="' . esc_attr($qr ? $qr->name : '') . '" required></label><label>Type<select name="type">';
        foreach ($this->types->all() as $key => $meta) { $html .= '<option value="' . esc_attr($key) . '" ' . selected($type, $key, false) . '>' . esc_html($meta['label']) . '</option>'; }
        $html .= '</select></label><label>Campaign<select name="campaign_id"><option value="0">Unassigned</option>';
        foreach ($this->campaigns->active() as $campaign) { $html .= '<option value="' . esc_attr($campaign->id) . '" ' . selected($qr ? $qr->campaignId : 0, $campaign->id, false) . '>' . esc_html($campaign->name) . '</option>'; }
        $html .= '</select></label></fieldset>';
        $html .= '<fieldset><legend>' . esc_html($this->types->label($type)) . '</legend>' . $this->typeFields($type, $payload, $qr) . '</fieldset>';
        return $html;
    }

    private function typeFields(string $type, array $payload, ?QRCode $qr): string {
        if ($type === 'dynamic_url') { return $this->input('Destination URL', 'payload[destination_url]', $payload['destination_url'] ?? ($qr ? $qr->destinationUrl : ''), 'url') . '<label>Status<select name="status"><option value="active" ' . selected($qr ? $qr->status : 'active', 'active', false) . '>Active</option><option value="paused" ' . selected($qr ? $qr->status : '', 'paused', false) . '>Paused</option></select></label>' . $this->input('Scheduled URL', 'scheduled_url', $qr ? (string) $qr->scheduledUrl : '', 'url') . $this->input('Schedule start', 'scheduled_start_at', $this->localInput($qr ? $qr->scheduledStartAt : null), 'datetime-local') . $this->input('Schedule end', 'scheduled_end_at', $this->localInput($qr ? $qr->scheduledEndAt : null), 'datetime-local') . $this->input('Expiry date/time', 'expires_at', $this->localInput($qr ? $qr->expiresAt : null), 'datetime-local') . $this->input('Fallback URL', 'fallback_url', $qr ? (string) $qr->fallbackUrl : '', 'url'); }
        if ($type === 'static_url') { return $this->input('URL', 'payload[url]', $payload['url'] ?? '', 'url'); }
        if ($type === 'wifi') { return $this->input('SSID', 'payload[ssid]', $payload['ssid'] ?? '') . $this->input('Password', 'payload[password]', $payload['password'] ?? '') . '<label>Encryption<select name="payload[encryption]"><option value="WPA" ' . selected($payload['encryption'] ?? 'WPA', 'WPA', false) . '>WPA/WPA2</option><option value="WEP" ' . selected($payload['encryption'] ?? '', 'WEP', false) . '>WEP</option><option value="NOPASS" ' . selected($payload['encryption'] ?? '', 'NOPASS', false) . '>None</option></select></label><label><input type="checkbox" name="payload[hidden]" value="1" ' . checked(!empty($payload['hidden']), true, false) . '> Hidden network</label>'; }
        if ($type === 'vcard') { $html = ''; foreach (['first_name'=>'First name','last_name'=>'Last name','organisation'=>'Organisation','job_title'=>'Job title','phone'=>'Phone','email'=>'Email','website'=>'Website','address'=>'Address'] as $key => $label) { $html .= $key === 'address' ? $this->textarea($label, 'payload[' . $key . ']', $payload[$key] ?? '') : $this->input($label, 'payload[' . $key . ']', $payload[$key] ?? '', $key === 'email' ? 'email' : ($key === 'website' ? 'url' : 'text')); } return $html; }
        if ($type === 'email') { return $this->input('Recipient', 'payload[recipient]', $payload['recipient'] ?? '', 'email') . $this->input('Subject', 'payload[subject]', $payload['subject'] ?? '') . $this->textarea('Body', 'payload[body]', $payload['body'] ?? ''); }
        if ($type === 'phone') { return $this->input('Phone number', 'payload[phone]', $payload['phone'] ?? ''); }
        if ($type === 'sms') { return $this->input('Phone number', 'payload[phone]', $payload['phone'] ?? '') . $this->textarea('Message', 'payload[message]', $payload['message'] ?? ''); }
        if ($type === 'location') { return $this->input('Latitude', 'payload[latitude]', $payload['latitude'] ?? '') . $this->input('Longitude', 'payload[longitude]', $payload['longitude'] ?? '') . $this->input('Label', 'payload[label]', $payload['label'] ?? ''); }
        return $this->textarea('Text', 'payload[text]', $payload['text'] ?? '');
    }

    private function studioDesignFields(QRDesignSettings $design): string {
        $html = '<fieldset><legend>Design</legend><label>Theme<select name="theme" class="qrb-theme-select">';
        foreach ($this->themes->all() as $key => $theme) { $settings = $this->themes->designSettings((string) $key); $html .= '<option value="' . esc_attr($key) . '" ' . selected($design->theme, $key, false) . ' data-foreground="' . esc_attr($settings->foregroundColor) . '" data-background="' . esc_attr($settings->backgroundColor) . '" data-margin="' . esc_attr((string) $settings->margin) . '" data-error="' . esc_attr($settings->errorCorrection) . '">' . esc_html($theme['label']) . '</option>'; }
        $html .= '</select></label>' . $this->input('Foreground colour', 'foreground_color', $design->foregroundColor, 'color') . $this->input('Background colour', 'background_color', $design->backgroundColor, 'color') . '<label><input type="checkbox" name="transparent_background" value="1" ' . checked($design->transparentBackground, true, false) . '> Transparent background</label><label>Error correction<select name="error_correction">';
        foreach (['L'=>'L - smallest','M'=>'M - balanced','Q'=>'Q - branded','H'=>'H - logo safe'] as $value => $label) { $html .= '<option value="' . esc_attr($value) . '" ' . selected($design->errorCorrection, $value, false) . '>' . esc_html($label) . '</option>'; }
        $logoNote = $this->entitlements->allows('logo_embedding') ? '' : '<p class="qrb-alert">Logo embedding is available on Pro and Business plans.</p>';
        return $html . '</select></label>' . $this->input('Quiet zone / margin', 'margin', (string) $design->margin, 'number') . $this->logoField($design) . $this->input('Logo size (%)', 'logo_size', (string) $design->logoSize, 'number') . $logoNote . '</fieldset>';
    }

    private function studioPreview(?QRCode $qr, string $type, array $payload, QRDesignSettings $design): string {
        $data = $qr ? $this->payloads->payloadForQrCode($qr) : $this->payloads->build($type, $payload);
        $html = '<div class="qrb-live-preview">';
        if (!$data || !$this->payloads->isPayloadValid($type, $data)) { $html .= '<p>Add content, save, and the preview will appear here.</p>'; }
        else { try { $html .= '<img class="qrb-preview" src="' . esc_attr($this->generator->generatePngDataUri($data, 260, $design)) . '" alt="">'; } catch (\Throwable $e) { $html .= '<p class="qrb-alert">Preview could not be generated.</p>'; } }
        return $html . '</div><p class="qrb-preview-status">Preview updates as you change content and design.</p><button type="button" class="qrb-button qrb-refresh-preview">Refresh Preview</button>';
    }

    private function qrDetail(int $id): string {
        $qr = $this->qrCodes->find($id); if (!$qr) { return '<h1>QR not found</h1>'; }
        $payload = $this->payloads->payloadForQrCode($qr);
        try { $preview = '<img class="qrb-preview" src="' . esc_attr($this->generator->generatePngDataUri($payload, 260, QRDesignSettings::fromQrCode($qr))) . '" alt="">'; } catch (\Throwable $e) { $preview = '<p class="qrb-alert">Preview could not be generated.</p>'; }
        $png = $this->downloadUrl($qr->id, 'png'); $svg = $this->downloadUrl($qr->id, 'svg');
        return '<div class="qrb-page-head"><h1>' . esc_html($qr->name) . '</h1><div class="qrb-actions"><a class="qrb-button" href="' . esc_url($this->studioUrl($qr->type, $qr->id)) . '">Edit in QR Studio</a><a class="qrb-button" href="' . esc_url($png) . '">Download PNG</a><a class="qrb-button" href="' . esc_url($svg) . '">Download SVG</a></div></div><div class="qrb-card qrb-detail">' . $preview . '<div><p><strong>Type:</strong> ' . esc_html($this->types->label($qr->type)) . '</p><p><strong>Destination / payload:</strong> ' . esc_html($qr->destinationUrl ?: $qr->staticPayload) . '</p><p><strong>Scans:</strong> ' . esc_html((string) $qr->scanCount) . '</p><p><code>' . esc_html($qr->isTrackable() ? $this->generator->trackingUrl($qr->shortcode) : $qr->shortcode) . '</code></p></div></div>' . $this->qrAnalyticsBlock($qr);
    }

    private function qrAnalyticsBlock(QRCode $qr): string {
        if (!$qr->isTrackable()) { return '<section class="qrb-card"><h2>Analytics</h2><p>Static QR codes do not use QR Buzz tracking. To collect analytics, create a Dynamic URL QR code.</p></section>'; }
        $stats = $this->perQrAnalytics->stats($qr->id);
        $html = '<section class="qrb-card"><h2>Analytics</h2>' . $this->metrics([['Total scans',$stats['total_scans']],['Today',$stats['scans_today']],['Last 7 days',$stats['scans_last_7_days']],['Last 30 days',$stats['scans_last_30_days']],['Latest scan',$stats['latest_scan'] ?: '-']]);
        if ($this->entitlements->allows('advanced_analytics')) { $html .= '<h3>Trend</h3>' . $this->barChart($this->perQrAnalytics->scanCountsByDay($qr->id, 30)) . '<div class="qrb-analytics-grid"><div><h3>Devices</h3>' . $this->breakdownTable($this->perQrAnalytics->deviceBreakdown($qr->id)) . '</div><div><h3>Browsers</h3>' . $this->breakdownTable($this->perQrAnalytics->browserBreakdown($qr->id)) . '</div><div><h3>Referrers</h3>' . $this->breakdownTable($this->perQrAnalytics->referrerBreakdown($qr->id)) . '</div></div><h3>Recent scans</h3>' . $this->recentScansTable($this->perQrAnalytics->recentScans($qr->id, 10)); }
        return $html . '</section>';
    }

    private function downloadQr(int $id, string $format): void {
        check_admin_referer('qrbuzz_app_download_' . $id);
        $qr = $this->qrCodes->find($id);
        if (!$qr) { wp_die(esc_html__('QR code not found.', 'qr-buzz'), 404); }
        try { $payload = $this->payloads->payloadForQrCode($qr); $design = QRDesignSettings::fromQrCode($qr); if ($format === 'svg') { $content = $this->generator->generateSvg($payload, 600, $design); $mime = 'image/svg+xml'; } else { $content = $this->generator->generatePng($payload, 600, $design); $mime = 'image/png'; } } catch (\Throwable $exception) { wp_die(esc_html__('QR download could not be generated. Please try again from QR Studio.', 'qr-buzz'), 500); }
        nocache_headers(); header('Content-Type: ' . $mime); header('Content-Disposition: attachment; filename="' . $this->filename($qr->name, $qr->shortcode, $format) . '"'); header('Content-Length: ' . strlen($content)); echo $content; exit;
    }

    private function campaignPage(): string { $rows = ''; foreach ($this->campaigns->all() as $c) { $rows .= '<tr><td><a href="' . esc_url(home_url('/app/campaigns/' . $c->id)) . '">' . esc_html($c->name) . '</a></td><td>' . esc_html($c->status) . '</td><td>' . esc_html((string) $c->qrCount) . '</td><td>' . esc_html((string) $c->scanCount) . '</td></tr>'; } return '<div class="qrb-page-head"><h1>Campaigns</h1></div><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_campaign', 'nonce', true, false) . '<label>Name<input name="name" required></label><label>Description<textarea name="description"></textarea></label><button class="qrb-button qrb-button-primary">Create Campaign</button></form><table class="qrb-table"><thead><tr><th>Campaign</th><th>Status</th><th>QR codes</th><th>Scans</th></tr></thead><tbody>' . ($rows ?: '<tr><td colspan="4">No campaigns yet.</td></tr>') . '</tbody></table>'; }
    private function campaignEdit(int $id): string { $c = $this->campaigns->find($id); if (!$c) { return '<h1>Campaign not found</h1>'; } return '<div class="qrb-page-head"><h1>Edit Campaign</h1><a class="qrb-button" href="/app/campaigns">Campaigns</a></div><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_campaign', 'nonce', true, false) . '<label>Name<input name="name" value="' . esc_attr($c->name) . '" required></label><label>Description<textarea name="description">' . esc_textarea($c->description) . '</textarea></label><label>Status<select name="status"><option value="active" ' . selected($c->status, 'active', false) . '>Active</option><option value="archived" ' . selected($c->status, 'archived', false) . '>Archived</option></select></label><button class="qrb-button qrb-button-primary">Save Campaign</button></form>' . $this->metrics([['QR codes',$c->qrCount],['Dynamic',$c->dynamicCount],['Static',$c->staticCount],['Scans',$c->scanCount]]); }

    private function analytics(): string {
        $rangeKey = sanitize_key((string) ($_GET['range'] ?? '30days'));
        $range = DateRange::fromRequest($rangeKey);
        $stats = $this->analyticsRepo->dashboardStats($range);
        $html = '<div class="qrb-page-head"><h1>Analytics</h1><form><select name="range" onchange="this.form.submit()"><option value="today" ' . selected($rangeKey, 'today', false) . '>Today</option><option value="7days" ' . selected($rangeKey, '7days', false) . '>Last 7 days</option><option value="30days" ' . selected($rangeKey, '30days', false) . '>Last 30 days</option><option value="all" ' . selected($rangeKey, 'all', false) . '>All time</option></select></form></div>' . $this->metrics([['Total scans',$stats['total_scans']],['Scans today',$stats['scans_today']],['Last 7 days',$stats['scans_last_7_days']],['Latest scan',$stats['latest_scan'] ?: '-']]);
        if (!$this->entitlements->allows('advanced_analytics')) { return $html . '<section class="qrb-card"><h2>Advanced analytics</h2><p>Upgrade to Pro or Business for trend charts, top QR codes, device, browser, referrer, and recent scan breakdowns.</p></section>'; }
        return $html . '<section class="qrb-card"><h2>Scan trend</h2>' . $this->barChart($this->analyticsRepo->scanCountsByDay($range)) . '</section><section class="qrb-card"><h2>Top QR codes</h2>' . $this->topQrTable($this->analyticsRepo->topQrCodes($range, 10)) . '</section><section class="qrb-card"><h2>Breakdowns</h2><div class="qrb-analytics-grid"><div><h3>Devices</h3>' . $this->breakdownTable($this->analyticsRepo->deviceBreakdown($range)) . '</div><div><h3>Browsers</h3>' . $this->breakdownTable($this->analyticsRepo->browserBreakdown($range)) . '</div></div></section><section class="qrb-card"><h2>Recent scans</h2>' . $this->recentScansTable($this->analyticsRepo->recentScans($range, 20)) . '</section>';
    }

    private function accountSettings(): string { $u = wp_get_current_user(); $p = $this->profiles->profile($u->ID); $notice = !empty($_GET['verify']) ? '<p class="qrb-alert">We sent a verification email to your new address.</p>' : ''; return '<h1>Account</h1>' . $notice . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_account', 'nonce', true, false) . '<label>First name<input name="first_name" value="' . esc_attr($u->first_name) . '"></label><label>Last name<input name="last_name" value="' . esc_attr($u->last_name) . '"></label><label>Email<input type="email" name="email" value="' . esc_attr($u->user_email) . '"></label><p>Email verification: ' . esc_html($p && $p->email_verified ? 'Verified' : 'Not verified') . '</p><label>New password<input type="password" name="password"></label><label>Confirm password<input type="password" name="password_confirm"></label><button class="qrb-button qrb-button-primary">Save account</button></form>'; }
    private function workspaceSettings(): string { $w = $this->workspaces->current(); return '<h1>Workspace</h1><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_workspace', 'nonce', true, false) . '<label>Name<input name="name" value="' . esc_attr($w->name) . '"></label><label>Website<input name="website_url" value="' . esc_attr($w->websiteUrl) . '"></label><label>Timezone<input name="timezone" value="' . esc_attr($w->timezone ?: wp_timezone_string()) . '"></label><label>Intended use<input name="intended_use" value="' . esc_attr($w->intendedUse) . '"></label><button class="qrb-button qrb-button-primary">Save workspace</button></form>'; }
    private function billingSettings(): string { $e = $this->entitlements->summary(); $sub = $e['subscription']; return '<h1>Billing</h1><div class="qrb-card"><p><strong>Current plan:</strong> ' . esc_html($e['plan']['label']) . '</p><p><strong>Status:</strong> ' . esc_html($sub['status']) . '</p><p><strong>Renewal:</strong> ' . esc_html($sub['renewal_date'] ?: '-') . '</p><p class="qrb-alert">Stripe is in test-mode prototype configuration for v0.9.5. If test keys are not configured, paid plan selection runs in local prototype mode.</p><a class="qrb-button" href="/app/onboarding?step=plan">Change plan</a></div>' . $this->usageCard(); }

    private function onboarding(): string { $step = sanitize_key((string) ($_GET['step'] ?? $this->workspaces->current()->onboardingStep ?: 'plan')); if ($step === 'workspace') { $notice = !empty($_GET['billing']) ? '<p class="qrb-alert">Stripe test keys are not configured yet, so this plan has been selected in local prototype mode.</p>' : ''; return '<h1>Create workspace</h1>' . $notice . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_onboarding', 'nonce', true, false) . '<input type="hidden" name="onboarding_action" value="workspace"><label>Workspace name<input name="workspace_name" value="' . esc_attr($this->workspaces->current()->name) . '"></label><label>Website<input name="website_url" type="url"></label><label>Intended use<select name="intended_use"><option value="marketing">Marketing campaigns</option><option value="wifi">WiFi access</option><option value="events">Events</option><option value="other">Other</option></select></label><button class="qrb-button qrb-button-primary">Continue</button></form>'; } if ($step === 'first_qr') { return '<h1>Create your first QR</h1><div class="qrb-card"><div class="qrb-actions"><a class="qrb-button" href="/app/qr/new?type=dynamic_url&studio=1">Dynamic Website QR</a><a class="qrb-button" href="/app/qr/new?type=static_url&studio=1">Static Website QR</a><a class="qrb-button" href="/app/qr/new?type=wifi&studio=1">WiFi QR</a></div><form method="post">' . wp_nonce_field('qrbuzz_onboarding', 'nonce', true, false) . '<input type="hidden" name="onboarding_action" value="complete"><button class="qrb-button qrb-button-primary">Skip for now</button></form></div>'; } return '<h1>Choose plan</h1>' . (!empty($_GET['error']) ? '<p class="qrb-alert">Stripe checkout is not available. You can still choose a paid plan in local prototype mode while test keys are configured.</p>' : '') . $this->planCards(true); }
    private function pricing(): string { return '<h1>Plans</h1>' . $this->planCards(false); }
    private function planCards(bool $form): string { $html = '<div class="qrb-plan-grid">'; foreach ($this->plans->plans() as $key => $plan) { $features = implode(', ', array_keys(array_filter($plan['features']))); $html .= '<div class="qrb-card"><h2>' . esc_html($plan['label']) . '</h2><p>' . esc_html($features) . '</p>'; if ($form) { $button = !$this->stripe->configured() && $key !== 'free' ? 'Choose ' . $plan['label'] . ' (prototype)' : 'Choose ' . $plan['label']; $html .= '<form method="post">' . wp_nonce_field('qrbuzz_onboarding', 'nonce', true, false) . '<input type="hidden" name="onboarding_action" value="plan"><input type="hidden" name="plan_key" value="' . esc_attr($key) . '"><button class="qrb-button qrb-button-primary">' . esc_html($button) . '</button></form>'; } $html .= '</div>'; } return $html . '</div>'; }

    private function registerForm(): string { return '<h1>Create your QR Buzz account</h1><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_register', 'nonce', true, false) . '<label>First name<input name="first_name" required></label><label>Last name<input name="last_name" required></label><label>Email<input type="email" name="email" required></label><label>Password<input type="password" name="password" required></label><label>Confirm password<input type="password" name="password_confirm" required></label><label><input type="checkbox" name="terms" value="1" required> I accept the Terms and Privacy Policy</label><button class="qrb-button qrb-button-primary">Create account</button></form>'; }
    private function loginForm(): string { return '<h1>Log in</h1><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_login', 'nonce', true, false) . '<label>Email or username<input name="login" required></label><label>Password<input type="password" name="password" required></label><label><input type="checkbox" name="remember" value="1"> Remember me</label><button class="qrb-button qrb-button-primary">Log in</button><p><a href="/forgot-password">Forgot password?</a></p></form>'; }
    private function passwordPage(): string { if (($_GET['action'] ?? '') === 'reset') { return '<h1>Reset password</h1><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_password_reset', 'nonce', true, false) . '<input type="hidden" name="mode" value="reset"><input type="hidden" name="key" value="' . esc_attr((string) ($_GET['key'] ?? '')) . '"><input type="hidden" name="login" value="' . esc_attr((string) ($_GET['login'] ?? '')) . '"><label>New password<input type="password" name="password"></label><label>Confirm password<input type="password" name="password_confirm"></label><button class="qrb-button qrb-button-primary">Reset password</button></form>'; } return '<h1>Forgot password</h1><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_password_request', 'nonce', true, false) . '<input type="hidden" name="mode" value="request"><label>Email or username<input name="login" required></label><button class="qrb-button qrb-button-primary">Send reset link</button></form>'; }

    private function verifyEmail(): void { $userId = absint($_GET['user'] ?? 0); $token = (string) ($_GET['token'] ?? ''); $ok = $userId && $token && $this->profiles->verifyByToken($userId, $token); $this->page('Verify email', '<h1>' . ($ok ? 'Email verified' : 'Verification failed') . '</h1><p><a class="qrb-button" href="/app/dashboard">Continue</a></p>'); }
    private function requireApp(): void { if (!is_user_logged_in()) { $this->redirect('/login?redirect=' . rawurlencode(home_url('/' . $this->path()))); } }
    private function redirectAfterLogin(): void { $w = $this->workspaces->current(); $this->redirect($w->onboardingComplete() ? '/app/dashboard' : '/app/onboarding'); }
    private function completeOnboarding(): void { $this->workspaceRepo->updateOnboarding($this->workspaces->id(), 'complete', 'complete'); $this->profiles->updateOnboarding(get_current_user_id(), 'complete', 'complete'); }
    private function payloadFromPost(string $type): array { $payload = isset($_POST['payload']) && is_array($_POST['payload']) ? wp_unslash($_POST['payload']) : []; $clean = []; foreach ($payload as $key => $value) { $clean[sanitize_key((string) $key)] = is_scalar($value) ? sanitize_textarea_field((string) $value) : ''; } if ($type === 'dynamic_url') { $clean['destination_url'] = esc_url_raw($clean['destination_url'] ?? ''); } if ($type === 'static_url') { $clean['url'] = esc_url_raw($clean['url'] ?? $clean['destination_url'] ?? ''); } if ($type === 'vcard' && isset($clean['website'])) { $clean['website'] = esc_url_raw($clean['website']); } if ($type === 'email' && isset($clean['recipient'])) { $clean['recipient'] = sanitize_email($clean['recipient']); } $clean['hidden'] = !empty($payload['hidden']) ? '1' : ''; return $clean; }
    private function usageCard(): string { $e = $this->entitlements->summary(); return '<section class="qrb-card"><h2>Usage</h2><p>QR assets: ' . esc_html((string) $e['usage']['qr_assets']) . ' / ' . esc_html($e['limits']['qr_assets'] === null ? 'Unlimited' : (string) $e['limits']['qr_assets']) . '</p><p>Campaigns: ' . esc_html((string) $e['usage']['campaigns']) . ' / ' . esc_html($e['limits']['campaigns'] === null ? 'Unlimited' : (string) $e['limits']['campaigns']) . '</p></section>'; }
    private function metrics(array $metrics): string { $html = '<div class="qrb-metrics">'; foreach ($metrics as $m) { $html .= '<div class="qrb-card"><span>' . esc_html((string) $m[0]) . '</span><strong>' . esc_html((string) $m[1]) . '</strong></div>'; } return $html . '</div>'; }
    private function hero(string $title, string $action = ''): string { return '<section class="qrb-hero"><h1>' . $title . '</h1>' . $action . '</section>'; }
    private function filename(string $name, string $shortcode, string $extension): string { $safe = sanitize_title($name); if ($safe === '') { $safe = strtolower($shortcode); } return $safe . '-' . strtolower($shortcode) . '.' . $extension; }
    private function studioUrl(string $type, int $id = 0): string { return $id > 0 ? home_url('/app/qr/' . $id . '/studio') : home_url('/app/qr/new?type=' . rawurlencode($type) . '&studio=1'); }
    private function downloadUrl(int $id, string $format): string { return wp_nonce_url(home_url('/app/qr/' . $id . '/download/' . $format), 'qrbuzz_app_download_' . $id); }
    private function input(string $label, string $name, string $value, string $type = 'text'): string { return '<label>' . esc_html($label) . '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></label>'; }
    private function textarea(string $label, string $name, string $value): string { return '<label>' . esc_html($label) . '<textarea name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea></label>'; }
    private function logoField(QRDesignSettings $design): string { $allowed = $this->entitlements->allows('logo_embedding'); $image = $allowed && $design->logoAttachmentId ? wp_get_attachment_image($design->logoAttachmentId, 'thumbnail') : ''; return '<label>Logo<input type="hidden" class="qrb-logo-id" name="logo_attachment_id" value="' . esc_attr($allowed ? (string) $design->logoAttachmentId : '0') . '"><span class="qrb-logo-actions"><button type="button" class="qrb-button qrb-select-logo" ' . disabled(!$allowed, true, false) . '>Choose logo</button><button type="button" class="qrb-button qrb-remove-logo" ' . disabled(!$allowed || !$design->logoAttachmentId, true, false) . '>Remove logo</button></span><span class="qrb-logo-preview">' . wp_kses_post($image) . '</span></label>'; }
    private function newDesignDefaults(): QRDesignSettings { $design = $this->brandKit->designDefaults(); $design->logoAttachmentId = 0; return $design; }
    private function optionalUrl(string $field): ?string { $url = esc_url_raw((string) ($_POST[$field] ?? '')); return trim($url) === '' ? null : $url; }
    private function optionalDateTime(string $field): ?string { $value = sanitize_text_field((string) ($_POST[$field] ?? '')); if ($value === '') { return null; } $timestamp = strtotime($value, current_time('timestamp')); return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null; }
    private function localInput(?string $date): string { return $date ? mysql2date('Y-m-d\TH:i', $date) : ''; }
    private function barChart(array $series): string { $max = max(1, ...array_map(static fn($r): int => (int) $r['scans'], $series)); $html = '<div class="qrb-bars">'; foreach ($series as $row) { $height = max(4, (int) round(((int) $row['scans'] / $max) * 90)); $html .= '<span title="' . esc_attr($row['date'] . ': ' . $row['scans']) . '" style="height:' . esc_attr((string) $height) . 'px"></span>'; } return $html . '</div>'; }
    private function breakdownTable(array $rows): string { if (!$rows) { return '<p>No data yet.</p>'; } $html = '<table class="qrb-table"><tbody>'; foreach ($rows as $row) { $html .= '<tr><td>' . esc_html((string) $row['label']) . '</td><td>' . esc_html((string) $row['count']) . '</td></tr>'; } return $html . '</tbody></table>'; }
    private function recentScansTable(array $rows): string { if (!$rows) { return '<p>No scans yet.</p>'; } $html = '<table class="qrb-table"><thead><tr><th>When</th><th>Referrer</th><th>User agent</th></tr></thead><tbody>'; foreach ($rows as $row) { $html .= '<tr><td>' . esc_html((string) $row->scanned_at) . '</td><td>' . esc_html($row->referrer ?: 'Direct / unknown') . '</td><td>' . esc_html((string) ($row->user_agent_summary ?? 'Unknown')) . '</td></tr>'; } return $html . '</tbody></table>'; }
    private function topQrTable(array $rows): string { if (!$rows) { return '<p>No scan data yet.</p>'; } $html = '<table class="qrb-table"><thead><tr><th>QR</th><th>Scans</th><th>Last scan</th></tr></thead><tbody>'; foreach ($rows as $row) { $html .= '<tr><td><a href="' . esc_url(home_url('/app/qr/' . $row->id)) . '">' . esc_html($row->name) . '</a></td><td>' . esc_html((string) $row->scan_count) . '</td><td>' . esc_html($row->last_scan ?: '-') . '</td></tr>'; } return $html . '</tbody></table>'; }

    private function enqueueAppAssets(): void { if (current_user_can('upload_files')) { wp_enqueue_media(); } }
    private function headAssets(): string { remove_action('wp_print_styles', 'print_emoji_styles'); ob_start(); wp_print_styles(); wp_print_head_scripts(); return (string) ob_get_clean(); }
    private function footerAssets(): string { ob_start(); wp_print_footer_scripts(); if (function_exists('wp_print_media_templates')) { wp_print_media_templates(); } return (string) ob_get_clean(); }
    private function appScripts(): string {
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'previewNonce' => wp_create_nonce('qrbuzz_preview'),
            'canUseMedia' => current_user_can('upload_files'),
        ];
        return '<script>window.QRBuzzApp=' . wp_json_encode($config) . ';</script><script>
(function(){
    function qs(selector, root){ return (root || document).querySelector(selector); }
    function qsa(selector, root){ return Array.prototype.slice.call((root || document).querySelectorAll(selector)); }
    function refreshPreview(){
        var form = qs(".qrb-studio-form");
        var target = qs(".qrb-live-preview");
        var status = qs(".qrb-preview-status");
        if (!form || !target || !window.QRBuzzApp) { return; }
        var data = new FormData(form);
        data.set("action", "qrbuzz_preview");
        data.set("nonce", window.QRBuzzApp.previewNonce);
        if (status) { status.textContent = "Refreshing preview..."; }
        fetch(window.QRBuzzApp.ajaxUrl, { method: "POST", credentials: "same-origin", body: data })
            .then(function(response){ return response.json(); })
            .then(function(result){
                if (result.success && result.data && result.data.data_uri) {
                    target.innerHTML = "<img class=\"qrb-preview\" src=\"" + result.data.data_uri + "\" alt=\"\">";
                    if (status) { status.textContent = result.data.message || "Preview refreshed."; }
                    return;
                }
                target.innerHTML = "<p>" + (result.data && result.data.message ? result.data.message : "Preview unavailable.") + "</p>";
                if (status) { status.textContent = "Preview could not be generated."; }
            })
            .catch(function(){ if (status) { status.textContent = "Preview could not be generated."; } });
    }
    function schedulePreview(){ clearTimeout(window.qrbuzzHostedPreviewTimer); window.qrbuzzHostedPreviewTimer = setTimeout(refreshPreview, 450); }
    document.addEventListener("click", function(event){
        var refresh = event.target.closest(".qrb-refresh-preview");
        if (refresh) { event.preventDefault(); refreshPreview(); }
        var selectLogo = event.target.closest(".qrb-select-logo");
        if (selectLogo) {
            event.preventDefault();
            if (!window.wp || !wp.media) { return; }
            var wrap = selectLogo.closest("label");
            var field = qs(".qrb-logo-id", wrap);
            var preview = qs(".qrb-logo-preview", wrap);
            var remove = qs(".qrb-remove-logo", wrap);
            var frame = wp.media({ title: "Choose QR logo", button: { text: "Use this logo" }, multiple: false, library: { type: "image" } });
            frame.on("select", function(){
                var attachment = frame.state().get("selection").first().toJSON();
                field.value = attachment.id;
                preview.innerHTML = "<img src=\"" + ((attachment.sizes && attachment.sizes.thumbnail) ? attachment.sizes.thumbnail.url : attachment.url) + "\" alt=\"\">";
                if (remove) { remove.disabled = false; }
                schedulePreview();
            });
            frame.open();
        }
        var removeLogo = event.target.closest(".qrb-remove-logo");
        if (removeLogo) {
            event.preventDefault();
            var logoWrap = removeLogo.closest("label");
            qs(".qrb-logo-id", logoWrap).value = "0";
            qs(".qrb-logo-preview", logoWrap).innerHTML = "";
            removeLogo.disabled = true;
            schedulePreview();
        }
    });
    document.addEventListener("change", function(event){
        if (!event.target.closest(".qrb-studio-form")) { return; }
        if (event.target.classList.contains("qrb-theme-select")) {
            var option = event.target.selectedOptions[0];
            var form = event.target.closest("form");
            if (option && form) {
                qs("[name=foreground_color]", form).value = option.dataset.foreground || "#000000";
                qs("[name=background_color]", form).value = option.dataset.background || "#ffffff";
                qs("[name=margin]", form).value = option.dataset.margin || "12";
                qs("[name=error_correction]", form).value = option.dataset.error || "H";
            }
        }
        schedulePreview();
    });
    document.addEventListener("input", function(event){ if (event.target.closest(".qrb-studio-form")) { schedulePreview(); } });
    window.qrbuzzRefreshPreview = refreshPreview;
})();</script>';
    }

    private function page(string $title, string $content): void { status_header(200); nocache_headers(); echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title>' . $this->styles() . '</head><body class="qrb-body"><main class="qrb-public"><a class="qrb-logo" href="/">QR Buzz</a>' . $content . '</main></body></html>'; exit; }
    private function app(string $title, string $content): void { status_header(200); nocache_headers(); $this->enqueueAppAssets(); $w = $this->workspaces->current(); echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title>' . $this->styles() . $this->headAssets() . '</head><body class="qrb-body"><div class="qrb-shell"><aside><a class="qrb-logo" href="/app/dashboard">QR Buzz</a><nav><a href="/app/dashboard">Dashboard</a><a href="/app/library">Library</a><a href="/app/qr/new">New QR</a><a href="/app/campaigns">Campaigns</a><a href="/app/analytics">Analytics</a><a href="/app/settings/account">Settings</a></nav></aside><div><header><strong>' . esc_html($w->name) . '</strong><nav><a href="/app/settings/billing">Billing</a><a href="/logout">Logout</a></nav></header><main>' . $content . '</main></div></div>' . $this->appScripts() . $this->footerAssets() . '</body></html>'; exit; }
    private function styles(): string { return '<style>:root{--qrb-primary:#0f766e;--qrb-accent:#2563eb;--qrb-bg:#f6f7f7;--qrb-surface:#fff;--qrb-border:#dcdcde;--qrb-text:#1d2327;--qrb-muted:#646970;--qrb-radius:6px}body.qrb-body{margin:0;background:var(--qrb-bg);color:var(--qrb-text);font-family:-apple-system,BlinkMacSystemFont,Segoe UI,sans-serif}.qrb-public{max-width:760px;margin:40px auto;padding:24px}.qrb-shell{display:grid;grid-template-columns:240px 1fr;min-height:100vh}.qrb-shell aside{background:#111827;color:#fff;padding:20px}.qrb-logo{font-weight:700;text-decoration:none;color:inherit;display:block;margin-bottom:20px}.qrb-shell aside a{color:#fff;display:block;padding:9px 0;text-decoration:none}.qrb-shell header{display:flex;justify-content:space-between;align-items:center;background:#fff;border-bottom:1px solid var(--qrb-border);padding:14px 22px}.qrb-shell main{padding:22px}.qrb-card,.qrb-hero{background:var(--qrb-surface);border:1px solid var(--qrb-border);border-radius:var(--qrb-radius);padding:18px;margin:0 0 16px}.qrb-form{display:grid;gap:12px}.qrb-form fieldset{border:1px solid var(--qrb-border);border-radius:var(--qrb-radius);padding:12px;display:grid;gap:10px}.qrb-form input,.qrb-form select,.qrb-form textarea{display:block;width:100%;max-width:560px;padding:9px;border:1px solid var(--qrb-border);border-radius:4px}.qrb-form input[type=color]{width:70px;height:42px;max-width:70px;padding:3px;cursor:pointer}.qrb-logo-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:6px}.qrb-logo-preview{display:block;margin-top:8px}.qrb-logo-preview img{max-width:72px;height:auto;border:1px solid var(--qrb-border);background:#fff}.qrb-preview-status{color:var(--qrb-muted);margin:10px 0}.qrb-button{display:inline-block;border:1px solid var(--qrb-border);background:#fff;border-radius:4px;padding:9px 12px;text-decoration:none;color:var(--qrb-text);cursor:pointer}.qrb-button-primary{background:var(--qrb-primary);border-color:var(--qrb-primary);color:#fff}.qrb-actions{display:flex;gap:8px;flex-wrap:wrap}.qrb-page-head{display:flex;justify-content:space-between;gap:12px;align-items:center}.qrb-metrics,.qrb-plan-grid,.qrb-type-grid,.qrb-analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}.qrb-card span{display:block;color:var(--qrb-muted)}.qrb-card strong{font-size:24px}.qrb-table{width:100%;border-collapse:collapse;background:#fff}.qrb-table th,.qrb-table td{border-bottom:1px solid var(--qrb-border);padding:10px;text-align:left}.qrb-alert{background:#fff7ed;border-left:4px solid #f97316;padding:10px}.qrb-detail,.qrb-studio{display:grid;grid-template-columns:280px 1fr;gap:20px}.qrb-preview{max-width:260px;height:auto}.qrb-studio-preview{align-self:start;position:sticky;top:16px}.qrb-bars{height:110px;display:flex;align-items:end;gap:3px;border-bottom:1px solid var(--qrb-border);padding-top:10px}.qrb-bars span{display:block;flex:1;background:var(--qrb-primary);min-width:4px}@media(max-width:780px){.qrb-shell{grid-template-columns:1fr}.qrb-shell aside{position:static}.qrb-shell aside nav{display:flex;gap:10px;overflow:auto}.qrb-detail,.qrb-studio{grid-template-columns:1fr}.qrb-studio-preview{position:static}}</style>'; }
    private function path(): string { return trim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/'); }
    private function redirect(string $path): void { wp_safe_redirect(str_starts_with($path, 'http') ? $path : home_url($path)); exit; }
    private function rateLimited(string $scope): bool { $key = 'qrbuzz_' . $scope . '_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? '')); $count = (int) get_transient($key); set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS); return $count > 12; }
    private function notFound(): void { status_header(404); $this->app('Not found', '<h1>Page not found</h1>'); }
}
