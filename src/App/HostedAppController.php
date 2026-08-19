<?php
namespace QRBuzz\App;

use QRBuzz\Account\AuthIdentityRepository;
use QRBuzz\Account\AuthService;
use QRBuzz\Account\GoogleAuthService;
use QRBuzz\Account\ProfileRepository;
use QRBuzz\Analytics\AnalyticsRepository;
use QRBuzz\Analytics\AnalyticsCsvExporter;
use QRBuzz\Analytics\DateRange;
use QRBuzz\Analytics\PerQRAnalyticsService;
use QRBuzz\Analytics\QRInsightService;
use QRBuzz\Assets\AssetRepository;
use QRBuzz\Assets\AssetStorage;
use QRBuzz\Billing\BillingPlanRegistry;
use QRBuzz\Billing\StripeService;
use QRBuzz\Billing\SubscriptionRepository;
use QRBuzz\Database\CampaignRepository;
use QRBuzz\Database\DestinationRuleRepository;
use QRBuzz\Database\QRRepository;
use QRBuzz\Database\WorkspaceRepository;
use QRBuzz\Membership\EntitlementService;
use QRBuzz\Membership\PlanRegistry;
use QRBuzz\Models\DestinationRule;
use QRBuzz\Models\QRCode;
use QRBuzz\QR\Design\BrandKitSettings;
use QRBuzz\QR\Design\QRDesignSettings;
use QRBuzz\QR\Design\QRThemeRegistry;
use QRBuzz\QR\QRGenerator;
use QRBuzz\QR\Types\QRPayloadService;
use QRBuzz\QR\Types\QRTypeRegistry;
use QRBuzz\Redirect\DestinationResolver;
use QRBuzz\Utils\DateTimeHelper;
use QRBuzz\Workspace\WorkspaceService;

class HostedAppController {

    private AuthService $auth;
    private AuthIdentityRepository $identities;
    private GoogleAuthService $google;
    private ProfileRepository $profiles;
    private WorkspaceRepository $workspaceRepo;
    private WorkspaceService $workspaces;
    private EntitlementService $entitlements;
    private PlanRegistry $plans;
    private BillingPlanRegistry $billingPlans;
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
    private DestinationRuleRepository $rules;
    private DestinationResolver $resolver;
    private QRInsightService $insights;
    private AssetRepository $assets;
    private AssetStorage $assetStorage;
    private AnalyticsCsvExporter $analyticsExporter;

    public function __construct() {
        $this->profiles = new ProfileRepository();
        $this->workspaceRepo = new WorkspaceRepository();
        $this->workspaces = new WorkspaceService($this->workspaceRepo);
        $this->plans = new PlanRegistry();
        $this->billingPlans = new BillingPlanRegistry();
        $this->entitlements = new EntitlementService($this->workspaces, $this->plans);
        $this->auth = new AuthService($this->profiles, $this->workspaceRepo);
        $this->identities = new AuthIdentityRepository();
        $this->google = new GoogleAuthService();
        $this->qrCodes = new QRRepository(null, null, $this->workspaces);
        $this->campaigns = new CampaignRepository($this->workspaces);
        $this->types = new QRTypeRegistry();
        $this->generator = new QRGenerator();
        $this->payloads = new QRPayloadService($this->types, $this->generator);
        $this->themes = new QRThemeRegistry();
        $this->brandKit = new BrandKitSettings($this->themes, $this->workspaces);
        $this->subscriptions = new SubscriptionRepository();
        $this->stripe = new StripeService($this->subscriptions, $this->workspaceRepo, null, null, $this->billingPlans);
        $this->analyticsRepo = new AnalyticsRepository(null, $this->workspaces);
        $this->analyticsExporter = new AnalyticsCsvExporter($this->entitlements, $this->workspaces);
        $this->perQrAnalytics = new PerQRAnalyticsService(null, $this->workspaces);
        $this->rules = new DestinationRuleRepository($this->workspaces);
        $this->resolver = new DestinationResolver($this->rules);
        $this->insights = new QRInsightService($this->perQrAnalytics, $this->resolver, $this->rules);
        $this->assets = new AssetRepository($this->workspaces);
        $this->assetStorage = new AssetStorage($this->assets, $this->workspaces);
    }

    public function init(): void { add_action('template_redirect', [$this, 'route'], 0); }

    public function route(): void {
        $path = $this->path();
        if (!in_array($path, ['pricing','register','login','forgot-password','verify-email','resend-verification','auth/google','auth/google/callback','logout'], true) && !str_starts_with($path, 'app')) { return; }
        $this->handlePost($path);
        if ($path === 'logout') { wp_logout(); wp_safe_redirect(home_url('/login')); exit; }
        if ($path === 'pricing') { $this->page('Pricing', $this->pricing()); }
        if ($path === 'register') { if (is_user_logged_in()) { $this->redirectAfterLogin(); } $this->page('Create account', $this->registerForm()); }
        if ($path === 'login') { if (is_user_logged_in()) { $this->redirectAfterLogin(); } $this->page('Log in', $this->loginForm()); }
        if ($path === 'forgot-password') { $this->page('Password recovery', $this->passwordPage()); }
        if ($path === 'verify-email') { $this->verifyEmail(); }
        if ($path === 'resend-verification') { $this->resendVerification(); }
        if ($path === 'auth/google') { $this->startGoogle(); }
        if ($path === 'auth/google/callback') { $this->finishGoogle(); }
        if (str_starts_with($path, 'app')) { $this->requireApp(); $this->appPage($path); }
    }

    private function handlePost(string $path): void {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { return; }
        if ($path === 'register') { $this->postRegister(); }
        if ($path === 'login') { $this->postLogin(); }
        if ($path === 'forgot-password') { $this->postPassword(); }
        if ($path === 'app/onboarding') { $this->postOnboarding(); }
        if ($path === 'app/library') { $this->postQrDelete(); }
        if ($path === 'app/qr/new') { $this->postQrStudio(); }
        if (preg_match('#^app/qr/(\d+)/studio$#', $path, $m)) { $this->postQrStudio((int) $m[1]); }
        if (preg_match('#^app/qr/(\d+)/smart-destinations$#', $path, $m)) { $this->postSmartDestination((int) $m[1]); }
        if ($path === 'app/campaigns') { $this->postCampaign(); }
        if (preg_match('#^app/campaigns/(\d+)$#', $path, $m)) { $this->postCampaign((int) $m[1]); }
        if ($path === 'app/settings/account') { $this->postAccount(); }
        if ($path === 'app/settings/workspace') { $this->postWorkspace(); }
        if ($path === 'app/settings/billing') { $this->postBilling(); }
    }

    private function postRegister(): void {
        check_admin_referer('qrbuzz_register', 'nonce');
        $plan = sanitize_key((string) ($_POST['plan'] ?? 'free'));
        if (!in_array($plan, ['free', 'pro', 'business'], true)) { $plan = 'free'; }
        $planQuery = $plan === 'free' ? '' : '&plan=' . rawurlencode($plan);
        if ($this->rateLimited('register')) { $this->redirect('/register?error=rate' . $planQuery); }
        $user = $this->auth->register($_POST);
        if (is_wp_error($user)) { $this->redirect('/register?error=' . rawurlencode($user->get_error_code()) . $planQuery); }
        if (in_array($plan, ['pro', 'business'], true)) {
            $this->completeOnboarding();
            $this->redirect('/app/settings/billing?upgrade=' . rawurlencode($plan));
        }
        $this->completeOnboardingAndRedirect();
    }

    private function postLogin(): void {
        check_admin_referer('qrbuzz_login', 'nonce');
        if ($this->rateLimited('login')) { $this->redirect('/login?error=rate'); }
        $user = $this->auth->login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''), !empty($_POST['remember']));
        if (is_wp_error($user)) { $this->redirect('/login?error=failed'); }
        $googleLink = sanitize_text_field((string) ($_POST['google_link'] ?? ''));
        if ($googleLink !== '') { $this->completeGoogleLink((int) $user->ID, $googleLink); }
        $this->redirectAfterLogin();
    }

    private function postPassword(): void {
        $mode = sanitize_key((string) ($_POST['mode'] ?? 'request'));
        if ($mode === 'reset') {
            check_admin_referer('qrbuzz_password_reset', 'nonce');
            $key = (string) ($_POST['key'] ?? '');
            $login = rawurldecode((string) ($_POST['login'] ?? ''));
            $user = check_password_reset_key($key, $login);
            $resetUrl = '/forgot-password?action=reset&key=' . rawurlencode($key) . '&login=' . rawurlencode($login);
            if (is_wp_error($user)) { $this->redirect($resetUrl . '&error=link'); }
            if (empty($_POST['password'])) { $this->redirect($resetUrl . '&error=required'); }
            if ($_POST['password'] !== ($_POST['password_confirm'] ?? '')) { $this->redirect($resetUrl . '&error=mismatch'); }
            if (!$this->auth->strongPassword((string) $_POST['password'])) { $this->redirect($resetUrl . '&error=weak'); }
            reset_password($user, (string) $_POST['password']);
            $this->redirect('/login?reset=1');
        }
        check_admin_referer('qrbuzz_password_request', 'nonce');
        $login = sanitize_text_field((string) ($_POST['login'] ?? ''));
        $user = get_user_by('email', $login) ?: get_user_by('login', $login);
        if ($user) { $key = get_password_reset_key($user); if (!is_wp_error($key)) { $url = add_query_arg(['action' => 'reset', 'key' => $key, 'login' => $user->user_login], home_url('/forgot-password')); wp_mail($user->user_email, 'Reset your QR Buzz password', $this->brandedEmail('Reset your QR Buzz password', $this->firstName($user), 'No problem, it happens. Use the button below to choose a fresh QR Buzz password and get back to creating.', 'Reset password', $url), ['Content-Type: text/html; charset=UTF-8']); } }
        $this->redirect('/forgot-password?sent=1');
    }

    private function postOnboarding(): void {
        check_admin_referer('qrbuzz_onboarding', 'nonce');
        $workspace = $this->workspaces->current();
        $action = sanitize_key((string) ($_POST['onboarding_action'] ?? ''));
        if ($action === 'plan') {
            $plan = sanitize_key((string) ($_POST['plan_key'] ?? 'free'));
            if (in_array($plan, ['pro', 'business'], true)) { $this->redirect('/app/settings/billing?upgrade=' . rawurlencode($plan)); }
            $this->completeOnboarding();
            $this->redirect('/app/dashboard');
        }
        if ($action === 'workspace') { $this->workspaceRepo->updateSettings($workspace->id, ['name' => sanitize_text_field((string) ($_POST['workspace_name'] ?? $workspace->name)), 'website_url' => esc_url_raw((string) ($_POST['website_url'] ?? '')), 'intended_use' => sanitize_key((string) ($_POST['intended_use'] ?? '')), 'timezone' => sanitize_text_field((string) ($_POST['timezone'] ?? wp_timezone_string()))]); $this->workspaceRepo->updateOnboarding($workspace->id, 'pending', 'first_qr'); $this->profiles->updateOnboarding(get_current_user_id(), 'pending', 'first_qr'); $this->redirect('/app/onboarding?step=first_qr'); }
        if ($action === 'complete') { $this->completeOnboarding(); (new \QRBuzz\Platform\PlatformEventRepository())->record('onboarding_completed', ['user_id' => get_current_user_id(), 'workspace_id' => $workspace->id]); $this->redirect('/app/dashboard?welcome=1'); }
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
        $designFallback = $existing ? QRDesignSettings::fromQrCode($existing) : $this->newDesignDefaults();
        $design = QRDesignSettings::fromPost($_POST, $designFallback);
        if (!$this->entitlements->allows('qr_styling')) { $design = QRDesignSettings::defaults(); }
        if (!$this->entitlements->allows('logo_embedding')) {
            $design->logoAttachmentId = $existing ? $designFallback->logoAttachmentId : 0;
            $design->logoAssetId = $existing ? $designFallback->logoAssetId : 0;
            $design->logoSize = $existing ? $designFallback->logoSize : 20;
        }
        if (!empty($_POST['set_as_brand_kit']) && $this->entitlements->allows('brand_kit')) { $this->brandKit->saveFromDesign($design); }
        $settings = ['type' => $type, 'payload_data' => $payload, 'static_payload' => $staticPayload, 'status' => $status, 'fallback_url' => $this->optionalUrl('fallback_url'), 'expires_at' => $this->optionalDateTime('expires_at'), 'scheduled_url' => $this->optionalUrl('scheduled_url'), 'scheduled_start_at' => $this->optionalDateTime('scheduled_start_at'), 'scheduled_end_at' => $this->optionalDateTime('scheduled_end_at'), 'campaign_id' => absint($_POST['campaign_id'] ?? 0), 'design' => $design->toArray()];
        if ($id > 0) { $this->qrCodes->update($id, $name, $destination, $status, $settings, get_current_user_id()); $this->redirect('/app/qr/' . $id . '?saved=1'); }
        $wasOnboarding = !$this->workspaces->current()->onboardingComplete();
        $newId = $this->qrCodes->create($name, $destination, $settings);
        if ($wasOnboarding) {
            (new \QRBuzz\Platform\PlatformEventRepository())->record('first_qr_created', ['user_id' => get_current_user_id(), 'workspace_id' => $this->workspaces->id(), 'qr_id' => $newId]);
        }
        $this->completeOnboarding();
        $this->redirect('/app/qr/' . $newId . '?created=1');
    }

    private function postSmartDestination(int $qrId): void {
        check_admin_referer('qrbuzz_app_smart_' . $qrId, 'nonce');
        $qr = $this->qrCodes->find($qrId);
        if (!$qr || !$qr->isTrackable()) { $this->redirect('/app/library?error=missing'); }
        if (!$this->entitlements->allows('smart_destinations')) { $this->redirect('/app/qr/' . $qrId . '?error=smart_locked#smart-destinations'); }
        $action = sanitize_key((string) ($_POST['smart_action'] ?? 'create'));
        $ruleId = absint($_POST['rule_id'] ?? 0);
        if ($action === 'delete' && $ruleId > 0) {
            $rule = $this->rules->find($ruleId);
            if ($rule && $rule->qrId === $qrId) { $this->rules->delete($ruleId, get_current_user_id()); }
            $this->redirect('/app/qr/' . $qrId . '?saved=1#smart-destinations');
        }
        if (in_array($action, ['activate', 'deactivate'], true) && $ruleId > 0) {
            $rule = $this->rules->find($ruleId);
            if ($rule && $rule->qrId === $qrId) { $this->rules->setStatus($ruleId, $action === 'activate' ? 'active' : 'inactive', get_current_user_id()); }
            $this->redirect('/app/qr/' . $qrId . '?saved=1#smart-destinations');
        }
        if ($action === 'create' && !$this->entitlements->canCreateSmartRule($qrId)) { $this->redirect('/app/qr/' . $qrId . '?error=smart_limit#smart-destinations'); }
        $data = $this->smartRuleDataFromPost();
        if ($data['name'] === '' || $data['destination_url'] === '') { $this->redirect('/app/qr/' . $qrId . '?error=smart_invalid#smart-destinations'); }
        if ($action === 'update' && $ruleId > 0) {
            $rule = $this->rules->find($ruleId);
            if ($rule && $rule->qrId === $qrId) { $this->rules->update($ruleId, $data, get_current_user_id()); }
        } else {
            $this->rules->create($qrId, $data, get_current_user_id());
        }
        $this->redirect('/app/qr/' . $qrId . '?saved=1#smart-destinations');
    }

    private function postQrDelete(): void {
        $qrId = absint($_POST['qr_id'] ?? 0);
        check_admin_referer('qrbuzz_app_delete_qr_' . $qrId, 'nonce');
        $qr = $this->qrCodes->find($qrId);
        if (!$qr || !current_user_can('read')) { wp_die(esc_html__('QR code not found.', 'qr-buzz'), 404); }
        if (!$this->qrCodes->delete($qrId)) { wp_die(esc_html__('QR code could not be deleted.', 'qr-buzz'), 500); }
        $this->redirect('/app/library?deleted=1');
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
        if (!empty($_POST['password'])) {
            if ($_POST['password'] !== ($_POST['password_confirm'] ?? '')) { $this->redirect('/app/settings/account?error=password_mismatch'); }
            if (!$this->auth->strongPassword((string) $_POST['password'])) { $this->redirect('/app/settings/account?error=weak_password'); }
            wp_set_password((string) $_POST['password'], $userId);
            wp_set_auth_cookie($userId);
        }
        $this->redirect('/app/settings/account?saved=1' . ($emailChanged ? '&verify=1' : ''));
    }

    private function postBilling(): void {
        check_admin_referer('qrbuzz_app_billing', 'nonce');
        if (!$this->workspaces->isOwner()) { $this->redirect('/app/settings/billing?error=permission'); }
        $workspaceId = $this->workspaces->id();
        $action = sanitize_key((string) ($_POST['billing_action'] ?? ''));
        if ($action === 'checkout') {
            $plan = sanitize_key((string) ($_POST['plan_key'] ?? ''));
            if (!in_array($plan, ['pro', 'business'], true)) { $this->redirect('/app/settings/billing?error=plan'); }
            $returnTo = in_array((string) ($_POST['return_to'] ?? ''), ['analytics', 'billing'], true) ? (string) $_POST['return_to'] : 'billing';
            $checkout = $this->stripe->createCheckoutSession($workspaceId, get_current_user_id(), $plan, $returnTo);
            if (is_wp_error($checkout)) { $this->redirect('/app/settings/billing?error=checkout&upgrade=' . rawurlencode($plan)); }
            wp_redirect($checkout);
            exit;
        }
        if ($action === 'portal') {
            $portal = $this->stripe->createPortalSession($workspaceId);
            if (is_wp_error($portal)) { $this->redirect('/app/settings/billing?error=portal'); }
            wp_redirect($portal);
            exit;
        }
        $this->redirect('/app/settings/billing');
    }

    private function postWorkspace(): void {
        check_admin_referer('qrbuzz_app_workspace', 'nonce');
        if (!$this->workspaces->isOwner()) { $this->redirect('/app/settings/workspace?error=permission'); }
        $this->workspaceRepo->updateSettings($this->workspaces->id(), [
            'name' => sanitize_text_field((string) ($_POST['name'] ?? '')),
            'website_url' => esc_url_raw((string) ($_POST['website_url'] ?? '')),
            'timezone' => sanitize_text_field((string) ($_POST['timezone'] ?? wp_timezone_string())),
            'intended_use' => sanitize_key((string) ($_POST['intended_use'] ?? '')),
        ]);
        if ($this->entitlements->allows('brand_kit')) { $this->brandKit->saveFromPost($_POST); }
        $this->redirect('/app/settings/workspace?saved=1');
    }

    private function appPage(string $path): void {
        $workspace = $this->workspaces->current();
        if ($path === 'app/analytics/export') { $this->analyticsExporter->export('workspace', 0, sanitize_key((string) ($_GET['range'] ?? '30days'))); }
        if (preg_match('#^app/qr/(\d+)/analytics/export$#', $path, $m)) { $this->analyticsExporter->export('qr', (int) $m[1], sanitize_key((string) ($_GET['range'] ?? '30days'))); }
        if (preg_match('#^app/campaigns/(\d+)/analytics/export$#', $path, $m)) { $this->analyticsExporter->export('campaign', (int) $m[1], sanitize_key((string) ($_GET['range'] ?? '30days'))); }
        if (!$workspace->onboardingComplete() || $path === 'app/onboarding') { $this->completeOnboardingAndRedirect($workspace); }
        if ($path === 'app' || $path === 'app/dashboard') { $this->app('Dashboard', $this->dashboard()); }
        if ($path === 'app/library') { $this->app('Library', $this->library()); }
        if ($path === 'app/assets') { $this->app('Assets', $this->assetLibrary()); }
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

    private function dashboard(): string {
        $summary = $this->qrCodes->dashboardSummary();
        $entitlements = $this->entitlements->summary();
        $recent = $summary['recent_qr_codes'];
        $scans = $summary['recent_scans'];
        $hasData = (int) $summary['total_qr_codes'] > 0;
        $html = '<section class="qrb-hero"><div class="qrb-page-header"><div><h1>' . esc_html($this->greeting()) . ', ' . esc_html(wp_get_current_user()->first_name ?: 'there') . '</h1><p>Print once. Change destinations, monitor scans, and keep every QR experience tidy.</p></div><div class="qrb-actions"><span class="qrb-badge">' . esc_html($entitlements['plan']['label']) . '</span><a class="qrb-button qrb-button-primary" href="/app/qr/new">New QR</a><a class="qrb-button" href="/app/campaigns">New Campaign</a></div></div></section>';
        $metrics = $this->metrics([['Total scans', $summary['total_scans'], 'All tracked QR scans'], ['Active QR codes', $summary['total_qr_codes'], 'Across dynamic and static assets'], ['Active campaigns', count($this->campaigns->active()), 'Campaign groups'], ['Last scan', $summary['latest_scan'] ?: '-', 'Most recent activity']]);
        if (!$hasData) {
            return $html . $this->emptyState('Create your first QR code', 'Start with a website, WiFi, business card or another QR type. Analytics and insights will appear once your QR codes are scanned.', '/app/qr/new', 'Create QR') . '<section class="qrb-card"><h2>Getting started</h2><ol class="qrb-checklist"><li>Create your first QR code</li><li>Add it to a campaign</li><li>Scan it from your phone</li><li>View your first insight</li></ol></section>' . $metrics . $this->tip('dashboard-first-qr', 'Your first insight starts here. Create a dynamic QR, scan it once, and QR Buzz will start building useful analytics.');
        }
        $html .= $metrics;
        $html .= '<section class="qrb-card"><h2>Quick actions</h2><div class="qrb-quick-actions"><a class="qrb-action-card" href="/app/library"><strong>QR Library</strong><span>Review, filter and download your QR codes.</span></a><a class="qrb-action-card" href="/app/qr/new?type=dynamic_url&studio=1"><strong>Dynamic QR</strong><span>Track scans and change destinations later.</span></a><a class="qrb-action-card" href="/app/qr/new?type=wifi&studio=1"><strong>WiFi QR</strong><span>Create a scannable network access code.</span></a><a class="qrb-action-card" href="/app/qr/new?type=vcard&studio=1"><strong>Business Card</strong><span>Share contact details in a clean vCard.</span></a><a class="qrb-action-card" href="/app/campaigns"><strong>Campaign</strong><span>Group related QR codes for reporting.</span></a></div></section>';
        $html .= '<div class="qrb-dashboard-grid"><section class="qrb-card"><h2>Recent QR codes</h2>' . $this->recentQrList($recent) . '</section><section class="qrb-card"><h2>Recent scan activity</h2>' . $this->recentScanList($scans) . '</section></div>';
        return $html . $this->usageCard();
    }
    private function library(): string {
        $search = sanitize_text_field((string) ($_GET['s'] ?? ''));
        $campaignId = absint($_GET['campaign'] ?? 0);
        $typeFilter = sanitize_key((string) ($_GET['type'] ?? ''));
        $statusFilter = sanitize_key((string) ($_GET['status'] ?? ''));
        $rows = array_values(array_filter($this->qrCodes->queryWithScanCounts($search, 1, 100, 'updated_at', 'DESC', $campaignId), function(QRCode $qr) use ($typeFilter, $statusFilter): bool {
            if ($typeFilter === 'dynamic' && !$qr->isTrackable()) { return false; }
            if ($typeFilter === 'static' && $qr->isTrackable()) { return false; }
            if ($typeFilter && !in_array($typeFilter, ['dynamic', 'static'], true) && $qr->type !== $typeFilter) { return false; }
            if ($statusFilter && $qr->effectiveStatus() !== $statusFilter) { return false; }
            return true;
        }));
        $html = $this->pageHeader('QR Library', 'Create, organise and manage your QR codes.', '<a class="qrb-button qrb-button-primary" href="/app/qr/new">New QR</a>');
        $html .= $this->libraryFilters($search, $campaignId, $typeFilter, $statusFilter);
        if (!$rows) { return $html . $this->emptyState($search || $campaignId || $typeFilter || $statusFilter ? 'No QR codes match those filters' : 'Create your first QR code', $search || $campaignId || $typeFilter || $statusFilter ? 'Try changing or clearing the filters to see more QR codes.' : 'Start with a website, WiFi, business card or another QR type.', '/app/qr/new', 'Create QR'); }
        $html .= '<div class="qrb-view-switch" aria-label="Library view"><button type="button" class="qrb-button qrb-button-primary" data-qrb-view="table">Table</button><button type="button" class="qrb-button" data-qrb-view="grid">Cards</button></div>';
        $html .= '<section class="qrb-library-view" data-qrb-library-view="table">' . $this->libraryTable($rows) . '</section>';
        $html .= '<section class="qrb-library-view qrb-card-grid" data-qrb-library-view="grid" hidden>';
        foreach ($rows as $qr) { $html .= $this->qrCard($qr); }
        return $html . '</section>';
    }

    private function assetLibrary(): string {
        $assets = $this->assetStorage->withUrls($this->assets->all());
        $html = $this->pageHeader('Asset Library', 'Upload and manage logo artwork for QR Studio.', '<a class="qrb-button qrb-button-primary" href="/app/qr/new">New QR</a>');
        $html .= '<section class="qrb-card qrb-asset-upload" data-qrb-asset-upload><div><h2>Upload logo</h2><p>PNG, JPG, JPEG or WebP. Maximum 5 MB and 6000px per side.</p></div><label class="qrb-dropzone"><input type="file" accept="image/png,image/jpeg,image/webp" data-qrb-asset-file><span>Drop an image here or browse</span></label><p class="qrb-preview-status" data-qrb-asset-status></p></section>';
        $html .= '<section class="qrb-card"><div class="qrb-page-head"><h2>Your assets</h2><button type="button" class="qrb-button" data-qrb-assets-refresh>Refresh</button></div><div class="qrb-asset-grid" data-qrb-asset-grid>';
        if (!$assets) {
            $html .= '<div class="qrb-empty" data-qrb-assets-empty><h2>No logo assets yet</h2><p>Upload reusable logo artwork here, then select it from QR Studio when designing a QR code.</p></div>';
        } else {
            foreach ($assets as $asset) {
                $html .= $this->assetCard($asset);
            }
        }
        return $html . '</div></section>';
    }

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
        return $this->pageHeader('QR Studio', $qr ? 'Edit content, destination, campaign and design without changing the QR code link.' : 'Choose the essentials first. Advanced destination and design options are available when they are relevant.', '<a class="qrb-button" href="/app/qr/new">Change type</a>') . (!empty($_GET['error']) ? '<p class="qrb-alert">Please check the highlighted details and try again.</p>' : '') . '<div class="qrb-studio"><form class="qrb-card qrb-form qrb-studio-form" method="post" action="' . esc_url($action) . '">' . wp_nonce_field('qrbuzz_app_qr', 'nonce', true, false) . '<input type="hidden" name="qr_id" value="' . esc_attr($qr ? $qr->id : 0) . '"><h2>' . esc_html($title) . '</h2><p class="qrb-unsaved-indicator" data-qrb-unsaved hidden>Unsaved changes</p>' . $this->studioContentFields($qr, $type, $payload) . $this->studioDesignFields($design) . '<div class="qrb-sticky-actions"><button class="qrb-button qrb-button-primary qrb-submit" data-loading-label="Saving...">' . esc_html($qr ? 'Update QR Code' : 'Create QR Code') . '</button><a class="qrb-button" href="' . esc_url($qr ? home_url('/app/qr/' . $qr->id) : home_url('/app/library')) . '">Cancel</a></div></form><aside class="qrb-card qrb-studio-preview"><h2>Live preview</h2>' . $this->studioPreview($qr, $type, $payload, $design) . '</aside></div>';
    }

    private function studioContentFields(?QRCode $qr, string $type, array $payload): string {
        $html = '<details class="qrb-studio-section" data-qrb-accordion="content" open><summary>Content</summary><div class="qrb-studio-section-body"><label>Name<input name="name" value="' . esc_attr($qr ? $qr->name : '') . '" required></label><label>Type<select name="type">';
        foreach ($this->types->all() as $key => $meta) { $html .= '<option value="' . esc_attr($key) . '" ' . selected($type, $key, false) . '>' . esc_html($meta['label']) . '</option>'; }
        $html .= '</select></label><label>Campaign<select name="campaign_id"><option value="0">Unassigned</option>';
        foreach ($this->campaigns->active() as $campaign) { $html .= '<option value="' . esc_attr($campaign->id) . '" ' . selected($qr ? $qr->campaignId : 0, $campaign->id, false) . '>' . esc_html($campaign->name) . '</option>'; }
        $html .= '</select></label></div></details>';
        $html .= $this->typeFields($type, $payload, $qr);
        return $html;
    }

    private function typeFields(string $type, array $payload, ?QRCode $qr): string {
        if ($type === 'dynamic_url') {
            $primary = $this->input('Destination URL', 'payload[destination_url]', $payload['destination_url'] ?? ($qr ? $qr->destinationUrl : ''), 'url') . '<label>Status<select name="status"><option value="active" ' . selected($qr ? $qr->status : 'active', 'active', false) . '>Active</option><option value="paused" ' . selected($qr ? $qr->status : '', 'paused', false) . '>Paused</option></select></label>';
            $schedule = $this->input('Scheduled URL', 'scheduled_url', $qr ? (string) $qr->scheduledUrl : '', 'url') . $this->dateTimeParts('Schedule start', 'scheduled_start_at', $qr ? $qr->scheduledStartAt : null) . $this->dateTimeParts('Schedule end', 'scheduled_end_at', $qr ? $qr->scheduledEndAt : null) . $this->dateTimeParts('Expiry date/time', 'expires_at', $qr ? $qr->expiresAt : null) . $this->input('Fallback URL', 'fallback_url', $qr ? (string) $qr->fallbackUrl : '', 'url');
            return '<details class="qrb-studio-section" data-qrb-accordion="destination" open><summary>Website / Dynamic URL</summary><div class="qrb-studio-section-body">' . $primary . '</div></details><details class="qrb-studio-section" data-qrb-accordion="scheduling-v2"><summary>Scheduling</summary><div class="qrb-studio-section-body">' . $schedule . '</div></details>';
        }
        if ($type === 'static_url') { return $this->studioSection($this->types->label($type), $this->input('URL', 'payload[url]', $payload['url'] ?? '', 'url')); }
        if ($type === 'wifi') { return $this->studioSection($this->types->label($type), $this->input('SSID', 'payload[ssid]', $payload['ssid'] ?? '') . $this->input('Password', 'payload[password]', $payload['password'] ?? '') . '<label>Encryption<select name="payload[encryption]"><option value="WPA" ' . selected($payload['encryption'] ?? 'WPA', 'WPA', false) . '>WPA/WPA2</option><option value="WEP" ' . selected($payload['encryption'] ?? '', 'WEP', false) . '>WEP</option><option value="NOPASS" ' . selected($payload['encryption'] ?? '', 'NOPASS', false) . '>None</option></select></label><label><input type="checkbox" name="payload[hidden]" value="1" ' . checked(!empty($payload['hidden']), true, false) . '> Hidden network</label>'); }
        if ($type === 'vcard') { $html = ''; foreach (['first_name'=>'First name','last_name'=>'Last name','organisation'=>'Organisation','job_title'=>'Job title','phone'=>'Phone','email'=>'Email','website'=>'Website','address'=>'Address'] as $key => $label) { $html .= $key === 'address' ? $this->textarea($label, 'payload[' . $key . ']', $payload[$key] ?? '') : $this->input($label, 'payload[' . $key . ']', $payload[$key] ?? '', $key === 'email' ? 'email' : ($key === 'website' ? 'url' : 'text')); } return $this->studioSection($this->types->label($type), $html); }
        if ($type === 'email') { return $this->studioSection($this->types->label($type), $this->input('Recipient', 'payload[recipient]', $payload['recipient'] ?? '', 'email') . $this->input('Subject', 'payload[subject]', $payload['subject'] ?? '') . $this->textarea('Body', 'payload[body]', $payload['body'] ?? '')); }
        if ($type === 'phone') { return $this->studioSection($this->types->label($type), $this->input('Phone number', 'payload[phone]', $payload['phone'] ?? '')); }
        if ($type === 'sms') { return $this->studioSection($this->types->label($type), $this->input('Phone number', 'payload[phone]', $payload['phone'] ?? '') . $this->textarea('Message', 'payload[message]', $payload['message'] ?? '')); }
        if ($type === 'location') { return $this->studioSection($this->types->label($type), $this->input('Latitude', 'payload[latitude]', $payload['latitude'] ?? '') . $this->input('Longitude', 'payload[longitude]', $payload['longitude'] ?? '') . $this->input('Label', 'payload[label]', $payload['label'] ?? '')); }
        return $this->studioSection($this->types->label($type), $this->textarea('Text', 'payload[text]', $payload['text'] ?? ''));
    }

    private function studioDesignFields(QRDesignSettings $design): string {
        $html = '<details class="qrb-studio-section" data-qrb-accordion="design" open><summary>Design</summary><div class="qrb-studio-section-body"><label>Theme<select name="theme" class="qrb-theme-select">';
        foreach ($this->themes->all() as $key => $theme) { $settings = $this->themes->designSettings((string) $key); $html .= '<option value="' . esc_attr($key) . '" ' . selected($design->theme, $key, false) . ' data-foreground="' . esc_attr($settings->foregroundColor) . '" data-background="' . esc_attr($settings->backgroundColor) . '" data-margin="' . esc_attr((string) $settings->margin) . '" data-error="' . esc_attr($settings->errorCorrection) . '">' . esc_html($theme['label']) . '</option>'; }
        $html .= '</select></label>' . $this->colorControl('Foreground colour', 'foreground_color', $design->foregroundColor) . $this->colorControl('Background colour', 'background_color', $design->backgroundColor) . '<label class="qrb-inline-check"><input type="checkbox" name="transparent_background" value="1" ' . checked($design->transparentBackground, true, false) . '><span>Transparent background</span></label><label>Error correction<select name="error_correction">';
        foreach (['L'=>'L - smallest','M'=>'M - balanced','Q'=>'Q - branded','H'=>'H - logo safe'] as $value => $label) { $html .= '<option value="' . esc_attr($value) . '" ' . selected($design->errorCorrection, $value, false) . '>' . esc_html($label) . '</option>'; }
        $logoSize = $this->entitlements->allows('logo_embedding') ? $this->input('Logo size (%)', 'logo_size', (string) $design->logoSize, 'number') : '';
        $html .= '</select></label>' . $this->input('Quiet zone / margin', 'margin', (string) $design->margin, 'number') . $this->logoField($design) . $logoSize . '</div></details>';
        if (!$this->entitlements->allows('qr_styling')) {
            return $html . '<details class="qrb-studio-section" data-qrb-accordion="advanced-style"><summary>Advanced style</summary><div class="qrb-studio-section-body"><p class="qrb-alert">Advanced QR styling is available on Pro and Business plans.</p></div></details>' . $this->brandKitDefaultsSection();
        }
        $html .= '<details class="qrb-studio-section" data-qrb-accordion="advanced-style"><summary>Advanced style</summary><div class="qrb-studio-section-body">';
        $html .= $this->select('Data module style', 'dot_style', $design->dotStyle, ['square' => 'Square', 'dot' => 'Dots', 'rounded' => 'Rounded']);
        $html .= $this->select('Finder pattern style', 'finder_style', $design->finderStyle, ['square' => 'Square', 'rounded' => 'Rounded', 'circle' => 'Circle']);
        $html .= $this->select('Finder centre style', 'finder_dot_style', $design->finderDotStyle, ['square' => 'Square', 'rounded' => 'Rounded', 'dot' => 'Dot']);
        $html .= $this->colorControl('Finder colour', 'finder_color', $design->finderColor ?: $design->foregroundColor);
        $html .= $this->input('Caption', 'caption', $design->caption);
        $html .= $this->select('Caption font', 'caption_font_family', $design->captionFontFamily, $this->captionFontOptions());
        $html .= $this->colorControl('Caption colour', 'caption_font_color', $design->captionFontColor);
        $html .= $this->input('Caption size', 'caption_font_size', (string) $design->captionFontSize, 'number');
        return $html . '<p class="qrb-help">Stylised QR codes should be tested on phones before printing, especially when using logos or low contrast colours.</p></div></details>' . $this->brandKitDefaultsSection();
    }

    private function studioPreview(?QRCode $qr, string $type, array $payload, QRDesignSettings $design): string {
        $data = $qr ? $this->payloads->payloadForQrCode($qr) : $this->payloads->build($type, $payload);
        $html = '<div class="qrb-live-preview">';
        if (!$data || !$this->payloads->isPayloadValid($type, $data)) { $html .= '<p>Add content, save, and the preview will appear here.</p>'; }
        else { try { $html .= '<img class="qrb-preview" src="' . esc_attr($this->generator->generatePngDataUri($data, 260, $design)) . '" alt="">'; } catch (\Throwable $e) { $html .= '<p class="qrb-alert">Preview could not be generated.</p>'; } }
        return $html . '</div><p class="qrb-preview-status">Preview updates as you change content and design. Scan-test before printing.</p><button type="button" class="qrb-button qrb-refresh-preview">Refresh Preview</button>';
    }

    private function qrDetail(int $id): string {
        $qr = $this->qrCodes->find($id); if (!$qr) { return '<h1>QR not found</h1>'; }
        $payload = $this->payloads->payloadForQrCode($qr);
        try { $preview = '<img class="qrb-preview" src="' . esc_attr($this->generator->generatePngDataUri($payload, 260, QRDesignSettings::fromQrCode($qr))) . '" alt="">'; } catch (\Throwable $e) { $preview = '<p class="qrb-alert">Preview could not be generated.</p>'; }
        $png = $this->downloadUrl($qr->id, 'png'); $svg = $this->downloadUrl($qr->id, 'svg');
        $downloads = '<details class="qrb-download-menu"><summary class="qrb-button">' . $this->icon('download') . '<span>Download</span>' . $this->icon('chevron-down') . '</summary><div><a href="' . esc_url($png) . '">PNG</a><a href="' . esc_url($svg) . '">SVG</a></div></details>';
        return '<div class="qrb-page-head qrb-qr-detail-head"><h1>' . esc_html($qr->name) . '</h1><div class="qrb-actions qrb-detail-actions"><a class="qrb-button" href="' . esc_url($this->studioUrl($qr->type, $qr->id)) . '">Edit in QR Studio</a>' . $downloads . '</div></div><div class="qrb-card qrb-detail">' . $preview . '<div><p><strong>Type:</strong> ' . esc_html($this->types->label($qr->type)) . '</p><p><strong>Destination / payload:</strong> ' . esc_html($qr->destinationUrl ?: $qr->staticPayload) . '</p><p><strong>Scans:</strong> ' . esc_html((string) $qr->scanCount) . '</p><p><code>' . esc_html($qr->isTrackable() ? $this->generator->trackingUrl($qr->shortcode) : $qr->shortcode) . '</code></p></div></div>' . $this->qrInsightsBlock($qr) . $this->smartDestinationsBlock($qr) . $this->qrAnalyticsBlock($qr);
    }

    private function qrInsightsBlock(QRCode $qr): string {
        if (!$this->entitlements->allows('insights')) { return '<section class="qrb-card"><h2>Insights</h2><p>Insights are available on paid plans.</p></section>'; }
        $html = '<section class="qrb-card"><h2>Insights</h2><ul class="qrb-insights-list">';
        foreach ($this->insights->insights($qr) as $insight) { $html .= '<li>' . esc_html($insight) . '</li>'; }
        return $html . '</ul></section>';
    }

    private function smartDestinationsBlock(QRCode $qr): string {
        if (!$qr->isTrackable()) { return '<section id="smart-destinations" class="qrb-card"><h2>Smart Destinations ' . $this->tooltip('Smart Destinations only apply to dynamic QR codes that route through QR Buzz.') . '</h2><p>Static QR codes encode their payload directly and do not use Smart Destinations.</p></section>'; }
        if (!$this->entitlements->allows('smart_destinations')) { return '<section id="smart-destinations" class="qrb-card"><h2>Smart Destinations ' . $this->tooltip('Smart Destinations automatically change where this QR sends visitors based on rules such as date, day or time.') . '</h2><p>Upgrade to Pro or Business to route scans with scheduled and conditional destinations.</p></section>'; }
        $resolution = $this->resolver->resolve($qr);
        $destination = $resolution->destinationUrl ?: $resolution->message;
        $rules = $this->rules->forQrCode($qr->id);
        $html = '<section id="smart-destinations" class="qrb-card"><div class="qrb-page-head"><h2>Smart Destinations ' . $this->tooltip('Smart Destinations automatically change where this QR sends visitors based on rules such as date, day or time.') . '</h2><a class="qrb-button" href="' . esc_url($this->studioUrl($qr->type, $qr->id)) . '">Edit destination settings</a></div>';
        $html .= $this->metrics([['Current result',$resolution->shouldRedirect ? 'Redirect' : 'Message'],['Reason',$this->resolutionReasonLabel($resolution->reason)],['Status',$resolution->scanStatus],['Active rule',$resolution->matchedRuleName ?: '-']]);
        if ($resolution->matchedRuleName) { $html .= '<p class="qrb-alert qrb-alert-success">Matched rule: ' . esc_html($resolution->matchedRuleName) . '</p>'; }
        $html .= '<p><strong>Resolved destination:</strong> ' . esc_html($destination ?: '-') . '</p>';
        if (!empty($_GET['error']) && str_starts_with((string) $_GET['error'], 'smart_')) { $html .= '<p class="qrb-alert">Smart Destination changes could not be saved. Check the rule name, destination URL and plan limits.</p>'; }
        $html .= '<div class="qrb-smart-rule-list">';
        if (!$rules) { $html .= '<p class="qrb-empty">No Smart Destination rules yet. The primary destination is currently used.</p>'; }
        foreach ($rules as $rule) { $html .= $this->smartDestinationRuleRow($rule); }
        return $html . '</div><h3>Add Smart Destination rule</h3>' . $this->smartDestinationRuleForm($qr->id) . '</section>';
    }

    private function smartDestinationRuleRow(DestinationRule $rule): string {
        $toggle = $rule->status === 'active' ? 'deactivate' : 'activate';
        $html = '<form class="qrb-form qrb-smart-rule-form" method="post" action="' . esc_url(home_url('/app/qr/' . $rule->qrId . '/smart-destinations')) . '">';
        $html .= wp_nonce_field('qrbuzz_app_smart_' . $rule->qrId, 'nonce', true, false) . '<input type="hidden" name="smart_action" value="update"><input type="hidden" name="rule_id" value="' . esc_attr((string) $rule->id) . '"><input type="hidden" name="status" value="' . esc_attr($rule->status) . '">';
        $html .= '<label>Rule name<input name="name" value="' . esc_attr($rule->name) . '" required></label><label>Priority<input name="priority" type="number" min="1" value="' . esc_attr((string) $rule->priority) . '"></label>';
        $html .= '<label class="qrb-form-wide">Destination URL<input name="destination_url" type="url" value="' . esc_attr($rule->destinationUrl) . '" required></label>';
        $html .= $this->dateTimeParts('Start', 'starts_at', $rule->startsAt) . $this->dateTimeParts('End', 'ends_at', $rule->endsAt);
        $html .= $this->dayCheckboxes($rule->dayNumbers()) . $this->timeRangeParts($rule->timeStart ?: '', $rule->timeEnd ?: '');
        $html .= '<div class="qrb-form-wide qrb-smart-rule-actions">' . $this->statusBadge($rule->status) . '<span class="qrb-help">' . esc_html($this->ruleConditionLabel($rule)) . '</span><button class="qrb-button qrb-button-primary">Save rule</button><button class="qrb-button" name="smart_action" value="' . esc_attr($toggle) . '">' . esc_html($toggle === 'activate' ? 'Activate' : 'Deactivate') . '</button><button class="qrb-button qrb-button-danger" name="smart_action" value="delete" onclick="return confirm(\'Delete this Smart Destination rule?\')">Delete</button></div>';
        return $html . '</form>';
    }

    private function smartDestinationRuleForm(int $qrId): string {
        $html = '<form class="qrb-form qrb-smart-rule-form" method="post" action="' . esc_url(home_url('/app/qr/' . $qrId . '/smart-destinations')) . '">';
        $html .= wp_nonce_field('qrbuzz_app_smart_' . $qrId, 'nonce', true, false) . '<input type="hidden" name="smart_action" value="create">';
        $html .= '<label>Rule name<input name="name" placeholder="Weekday lunch offer" required></label><label>Priority<input name="priority" type="number" min="1" value="10"></label>';
        $html .= '<label class="qrb-form-wide">Destination URL<input name="destination_url" type="url" placeholder="https://example.com/lunch" required></label>';
        $html .= $this->dateTimeParts('Start', 'starts_at', null) . $this->dateTimeParts('End', 'ends_at', null);
        $html .= $this->dayCheckboxes([]) . $this->timeRangeParts('', '');
        return $html . '<div class="qrb-form-wide qrb-smart-rule-actions"><button class="qrb-button qrb-button-primary">Add rule</button><span class="qrb-help">Leave conditions blank for an always-active rule.</span></div></form>';
    }

    private function qrAnalyticsBlock(QRCode $qr): string {
        if (!$qr->isTrackable()) { return '<section id="analytics" class="qrb-card"><h2>Analytics ' . $this->tooltip('Static QR codes do not pass through QR Buzz, so scan tracking is not available for them.') . '</h2><p>Static QR codes do not use QR Buzz tracking. To collect analytics, create a Dynamic URL QR code.</p></section>'; }
        $stats = $this->perQrAnalytics->stats($qr->id);
        $html = '<section id="analytics" class="qrb-card"><div class="qrb-page-head"><h2>Analytics ' . $this->tooltip('Analytics are collected when dynamic QR codes are scanned through their QR Buzz tracking URL.') . '</h2>' . $this->analyticsExportAction('qr', $qr->id, '30days') . '</div>' . $this->analyticsObservation((int) $stats['total_scans'], (int) $stats['scans_today'], (int) $stats['scans_last_7_days']) . $this->metricsCompact([['Total scans',$stats['total_scans']],['Today',$stats['scans_today']],['Last 7 days',$stats['scans_last_7_days']],['Last 30 days',$stats['scans_last_30_days']],['Latest scan',$stats['latest_scan'] ?: '-']]);
        if (!$this->entitlements->allows('csv_export')) { $html .= $this->premiumUpsell('Export this QR analytics', 'Download this QR code’s scan data as CSV with Pro or Business.'); }
        if ($this->entitlements->allows('advanced_analytics')) { $html .= '<h3>Trend</h3>' . $this->barChart($this->perQrAnalytics->scanCountsByDay($qr->id, 30)) . '<div class="qrb-analytics-grid"><div><h3>Devices</h3>' . $this->breakdownTable($this->perQrAnalytics->deviceBreakdown($qr->id)) . '</div><div><h3>Browsers</h3>' . $this->breakdownTable($this->perQrAnalytics->browserBreakdown($qr->id)) . '</div><div><h3>Referrers</h3>' . $this->breakdownTable($this->perQrAnalytics->referrerBreakdown($qr->id)) . '</div></div><h3>Recent scans</h3>' . $this->recentScansTable($this->perQrAnalytics->recentScans($qr->id, 10)); }
        return $html . '</section>';
    }

    private function analyticsExportAction(string $scope, int $entityId, string $rangeKey): string {
        if (!$this->entitlements->allows('csv_export')) { return '<a class="qrb-button" href="' . esc_url(home_url('/app/analytics?upgrade=csv_export')) . '">Export CSV 🔒</a>'; }
        $url = add_query_arg([
            'action' => 'qrbuzz_analytics_export',
            'scope' => in_array($scope, ['workspace', 'qr', 'campaign'], true) ? $scope : 'workspace',
            'entity_id' => absint($entityId),
            'range' => sanitize_key($rangeKey),
        ], admin_url('admin-post.php'));
        $url = wp_nonce_url($url, 'qrbuzz_analytics_export');
        return '<a class="qrb-button qrb-button-primary" href="' . esc_url($url) . '">Export CSV</a>';
    }

    private function premiumUpsell(string $title, string $message): string {
        return '<section class="qrb-card qrb-premium"><span class="qrb-badge">Pro &amp; Business</span><h2>' . esc_html($title) . '</h2><p>' . esc_html($message) . '</p><div class="qrb-actions"><a class="qrb-button qrb-button-primary" href="/app/settings/billing?upgrade=pro&return_to=analytics">View plans</a><a class="qrb-button" href="/app/analytics">Not now</a></div></section>';
    }

    private function billingStatusLabel(string $status): string {
        $labels = ['free' => 'Free', 'active' => 'Active', 'trialing' => 'Trial', 'past_due' => 'Payment issue', 'incomplete' => 'Payment incomplete', 'incomplete_expired' => 'Payment incomplete', 'canceled' => 'Cancelled', 'cancelled' => 'Cancelled', 'unpaid' => 'Payment required', 'paused' => 'Paused'];
        return $labels[sanitize_key($status)] ?? 'Pending';
    }

    private function downloadQr(int $id, string $format): void {
        check_admin_referer('qrbuzz_app_download_' . $id);
        $qr = $this->qrCodes->find($id);
        if (!$qr) { wp_die(esc_html__('QR code not found.', 'qr-buzz'), 404); }
        try { $payload = $this->payloads->payloadForQrCode($qr); $design = QRDesignSettings::fromQrCode($qr); if ($format === 'svg') { $content = $this->generator->generateSvg($payload, 2000, $design); $mime = 'image/svg+xml'; } else { $content = $this->generator->generatePng($payload, 2000, $design); $mime = 'image/png'; } (new \QRBuzz\Platform\PlatformEventRepository())->record('qr_downloaded', ['user_id' => get_current_user_id(), 'workspace_id' => $qr->workspaceId, 'object_type' => 'qr', 'object_id' => $qr->id, 'metadata' => ['format' => $format]]); } catch (\Throwable $exception) { wp_die(esc_html__('QR download could not be generated. Please try again from QR Studio.', 'qr-buzz'), 500); }
        nocache_headers(); header('Content-Type: ' . $mime); header('Content-Disposition: attachment; filename="' . $this->filename($qr->name, $qr->shortcode, $format) . '"'); header('Content-Length: ' . strlen($content)); echo $content; exit;
    }

    private function campaignPage(): string { $rows = ''; foreach ($this->campaigns->all() as $c) { $rows .= '<tr><td><a href="' . esc_url(home_url('/app/campaigns/' . $c->id)) . '">' . esc_html($c->name) . '</a><br><small>' . esc_html($c->description ?: 'No description yet') . '</small></td><td>' . $this->statusBadge($c->status) . '</td><td>' . esc_html((string) $c->qrCount) . '</td><td>' . esc_html((string) $c->scanCount) . '</td><td><a class="qrb-button" href="' . esc_url(home_url('/app/campaigns/' . $c->id)) . '">Edit</a></td></tr>'; } $table = $rows ? '<div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>Campaign</th><th>Status</th><th>QR codes</th><th>Scans</th><th>Actions</th></tr></thead><tbody>' . $rows . '</tbody></table></div>' : $this->emptyState('Create your first campaign', 'Campaigns help group related QR codes and keep reporting tidy.', '/app/campaigns', 'Create Campaign'); return $this->pageHeader('Campaigns', 'Group related QR codes and track campaign-level activity.') . '<div class="qrb-dashboard-grid"><form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_campaign', 'nonce', true, false) . '<h2>New campaign</h2><label>Name<input name="name" required></label><label>Description<textarea name="description"></textarea></label><button class="qrb-button qrb-button-primary">Create Campaign</button></form><section class="qrb-card"><h2>Campaign list</h2>' . $table . '</section></div>'; }
    private function campaignEdit(int $id): string { $c = $this->campaigns->find($id); if (!$c) { return $this->emptyState('Campaign not found', 'That campaign could not be found in this workspace.', '/app/campaigns', 'Back to Campaigns'); } return $this->pageHeader('Edit Campaign', 'Update the campaign name, description and status.', '<a class="qrb-button" href="/app/campaigns">Campaigns</a>' . $this->analyticsExportAction('campaign', $c->id, '30days')) . $this->metrics([['QR codes',$c->qrCount],['Dynamic',$c->dynamicCount],['Static',$c->staticCount],['Scans',$c->scanCount]]) . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_campaign', 'nonce', true, false) . '<label>Name<input name="name" value="' . esc_attr($c->name) . '" required></label><label>Description<textarea name="description">' . esc_textarea($c->description) . '</textarea></label><label>Status<select name="status"><option value="active" ' . selected($c->status, 'active', false) . '>Active</option><option value="archived" ' . selected($c->status, 'archived', false) . '>Archived</option></select></label><button class="qrb-button qrb-button-primary">Save Campaign</button></form>'; }

    private function analytics(): string {
        $rangeKey = sanitize_key((string) ($_GET['range'] ?? '30days'));
        $range = DateRange::fromRequest($rangeKey);
        $stats = $this->analyticsRepo->dashboardStats($range);
        $actions = '<form><select name="range" onchange="this.form.submit()"><option value="today" ' . selected($rangeKey, 'today', false) . '>Today</option><option value="7days" ' . selected($rangeKey, '7days', false) . '>Last 7 days</option><option value="30days" ' . selected($rangeKey, '30days', false) . '>Last 30 days</option><option value="all" ' . selected($rangeKey, 'all', false) . '>All time</option></select></form>' . $this->analyticsExportAction('workspace', 0, $rangeKey);
        $html = $this->pageHeader('Analytics', 'Understand scan activity across your QR codes.', $actions) . $this->metrics([['Total scans',$stats['total_scans']],['Scans today',$stats['scans_today']],['Last 7 days',$stats['scans_last_7_days']],['Latest scan',$stats['latest_scan'] ?: '-']]);
        if (!$this->entitlements->allows('advanced_analytics')) { return $html . $this->premiumUpsell('Export your analytics', 'CSV exports and advanced analytics are available on Pro and Business. Take your QR Buzz data into Excel, Google Sheets or your own reporting workflow.') . '<section class="qrb-card"><h2>Advanced analytics</h2><p>Upgrade to Pro or Business for trend charts, top QR codes, device, browser, referrer, and recent scan breakdowns.</p></section>'; }
        if (($_GET['upgrade'] ?? '') === 'csv_export') { $html .= $this->premiumUpsell('Export your analytics', 'CSV exports are available on Pro and Business plans.'); }
        return $html . $this->tip('analytics-first-visit', 'Analytics begin after a dynamic QR code receives its first scan. Static QR codes are listed in QR Buzz but do not collect scan data.') . '<section class="qrb-card"><h2>Scans over time</h2><p>See how scan activity changed during the selected period.</p>' . $this->barChart($this->analyticsRepo->scanCountsByDay($range)) . '</section><section class="qrb-card"><h2>Top-performing QR codes</h2><p>The QR codes receiving the most scans during this period.</p>' . $this->topQrTable($this->analyticsRepo->topQrCodes($range, 10)) . '</section><section class="qrb-card"><h2>Breakdowns</h2><div class="qrb-analytics-grid"><div><h3>Devices</h3>' . $this->breakdownTable($this->analyticsRepo->deviceBreakdown($range)) . '</div><div><h3>Browsers</h3>' . $this->breakdownTable($this->analyticsRepo->browserBreakdown($range)) . '</div></div></section><section class="qrb-card"><h2>Recent scans</h2>' . $this->recentScansTable($this->analyticsRepo->recentScans($range, 20)) . '</section>';
    }

    private function accountSettings(): string {
        $u = wp_get_current_user();
        $p = $this->profiles->profile($u->ID);
        $notice = '';
        if (!empty($_GET['saved'])) { $notice .= '<p class="qrb-alert qrb-alert-success">Your account details have been saved.</p>'; }
        if (!empty($_GET['verify'])) {
            $verify = sanitize_key((string) $_GET['verify']);
            $notice .= $verify === 'rate'
                ? '<p class="qrb-alert" role="alert">A verification email was sent recently. Please wait a few minutes before requesting another.</p>'
                : '<p class="qrb-alert qrb-alert-success">We sent a verification email to your new address.</p>';
        }
        if (isset($_GET['error'])) {
            $error = sanitize_key((string) $_GET['error']);
            $messages = [
                'email' => 'Enter a valid email address.',
                'email_taken' => 'That email address is already used by another account.',
                'save' => 'We could not save your account details. Please try again.',
                'password_mismatch' => 'The new passwords do not match. Enter the same password in both fields.',
                'weak_password' => 'Your new password does not meet the requirements below.',
            ];
            $notice .= '<p class="qrb-alert" role="alert">' . esc_html($messages[$error] ?? 'We could not save your account details. Please check the form and try again.') . '</p>';
        }
        return $this->settingsNav('account') . $this->pageHeader('Account settings', 'Manage your profile, email address and password.') . $notice . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_account', 'nonce', true, false) . '<label>First name<input name="first_name" autocomplete="given-name" value="' . esc_attr($u->first_name) . '"></label><label>Last name<input name="last_name" autocomplete="family-name" value="' . esc_attr($u->last_name) . '"></label><label>Email<input type="email" name="email" autocomplete="email" value="' . esc_attr($u->user_email) . '"></label><p>Email verification: ' . esc_html($p && $p->email_verified ? 'Verified' : 'Not verified') . '</p>' . $this->passwordRequirements() . '<label>New password<input type="password" name="password" autocomplete="new-password"></label><label>Confirm password<input type="password" name="password_confirm" autocomplete="new-password"></label><button class="qrb-button qrb-button-primary">Save account</button></form>';
    }
    private function workspaceSettings(): string {
        $w = $this->workspaces->current();
        $brand = $this->brandKit->get();
        $workspaceFields = '<fieldset><legend>Workspace</legend><label>Name<input name="name" value="' . esc_attr($w->name) . '"></label><label>Website<input name="website_url" value="' . esc_attr($w->websiteUrl) . '"></label><label>Timezone<input name="timezone" value="' . esc_attr($w->timezone ?: wp_timezone_string()) . '"></label><label>Intended use<input name="intended_use" value="' . esc_attr($w->intendedUse) . '"></label></fieldset>';
        if ($this->entitlements->allows('brand_kit')) {
            $brandKitFields = '<fieldset><legend>Brand Kit foundation</legend><label>Brand name<input name="brand_name" value="' . esc_attr((string) $brand['brand_name']) . '"></label>' . $this->colorControl('Primary colour', 'primary_color', (string) $brand['primary_color']) . $this->colorControl('Secondary colour', 'secondary_color', (string) $brand['secondary_color']) . $this->select('Default theme', 'default_theme', (string) $brand['default_theme'], $this->themeOptions()) . $this->colorControl('Default foreground', 'foreground_color', (string) $brand['foreground_color']) . $this->colorControl('Default background', 'background_color', (string) $brand['background_color']) . $this->select('Default error correction', 'default_error_correction', (string) $brand['default_error_correction'], ['L' => 'L - smallest', 'M' => 'M - balanced', 'Q' => 'Q - branded', 'H' => 'H - logo safe']) . $this->select('Default data module style', 'dot_style', (string) $brand['dot_style'], ['square' => 'Square', 'dot' => 'Dots', 'rounded' => 'Rounded']) . $this->select('Default finder style', 'finder_style', (string) $brand['finder_style'], ['square' => 'Square', 'rounded' => 'Rounded', 'circle' => 'Circle']) . $this->select('Default finder centre', 'finder_dot_style', (string) $brand['finder_dot_style'], ['square' => 'Square', 'rounded' => 'Rounded', 'dot' => 'Dot']) . $this->colorControl('Default finder colour', 'finder_color', (string) ($brand['finder_color'] ?: $brand['foreground_color'])) . $this->select('Caption font', 'caption_font_family', (string) $brand['caption_font_family'], $this->captionFontOptions()) . $this->colorControl('Caption colour', 'caption_font_color', (string) $brand['caption_font_color']) . $this->input('Caption size', 'caption_font_size', (string) $brand['caption_font_size'], 'number') . $this->input('Quiet zone / margin', 'margin', (string) $brand['margin'], 'number') . $this->input('Logo size (%)', 'logo_size', (string) $brand['logo_size'], 'number') . '<p class="qrb-help">These defaults are used when creating new QR codes. Use QR Studio\'s "Set as Brand Kit defaults" checkbox to save a design while creating a QR.</p></fieldset>';
        } else {
            $brandKitFields = '<fieldset class="qrb-premium"><legend>Brand Kit 🔒</legend><p>Save reusable workspace-wide design defaults with Pro or Business.</p><a class="qrb-button qrb-button-primary" href="' . esc_url(home_url('/app/settings/billing?upgrade=pro')) . '">Upgrade</a></fieldset>';
        }
        return $this->settingsNav('workspace') . $this->pageHeader('Workspace and Brand Kit', 'Set the workspace identity and defaults used across QR Buzz.') . $this->tip('brand-kit-first-visit', 'Save your workspace identity once, then QR Studio can use those defaults when available on your plan.') . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_app_workspace', 'nonce', true, false) . $workspaceFields . $brandKitFields . '<button class="qrb-button qrb-button-primary">Save workspace</button></form>';
    }

    private function billingSettings(): string {
        $summary = $this->entitlements->summary();
        $subscription = $summary['subscription'];
        $planKey = $summary['plan']['key'];
        $notice = '';
        if (($_GET['checkout'] ?? '') === 'success') {
            $notice .= '<p class="qrb-alert qrb-alert-success">Payment received — we are activating your ' . esc_html($this->plans->label(sanitize_key((string) ($_GET['plan'] ?? 'pro')))) . ' plan. Stripe confirmation controls when paid features become available.</p>';
        }
        if (($_GET['checkout'] ?? '') === 'cancelled') { $notice .= '<p class="qrb-alert">Checkout was cancelled. Your current plan has not changed.</p>'; }
        if (($subscription['status'] ?? '') === 'past_due') { $notice .= '<p class="qrb-alert">Your subscription needs attention. Update your payment method to keep your paid features active.</p>'; }
        if (!empty($_GET['error'])) {
            $message = sanitize_key((string) $_GET['error']) === 'portal' ? 'We could not open billing management. Please try again.' : 'We could not start checkout right now. Please try again.';
            $notice .= '<p class="qrb-alert" role="alert">' . esc_html($message) . '</p>';
        }
        $status = $this->billingStatusLabel((string) ($subscription['status'] ?? 'free'));
        $renewal = (string) ($subscription['renewal_date'] ?? '');
        $period = $renewal !== '' ? wp_date('j F Y', strtotime($renewal)) : '';
        $description = $this->billingPlans->plan($planKey)['description'];
        $html = $this->settingsNav('billing') . $this->pageHeader('Billing', 'Review your plan, usage and billing options.') . $notice;
        $html .= '<section class="qrb-card"><h2>Your plan</h2><p class="qrb-plan-name"><strong>' . esc_html($summary['plan']['label']) . '</strong></p><p>' . esc_html($description) . '</p><p><strong>Status:</strong> ' . esc_html($status) . '</p>';
        if ($period !== '') {
            $periodLabel = !empty($subscription['cancel_at_period_end']) ? 'Cancels on' : 'Renews on';
            $html .= '<p><strong>' . esc_html($periodLabel) . ':</strong> ' . esc_html($period) . '</p>';
        }
        if (!empty($subscription['customer_id'])) {
            $html .= '<form method="post">' . wp_nonce_field('qrbuzz_app_billing', 'nonce', true, false) . '<input type="hidden" name="billing_action" value="portal"><button class="qrb-button qrb-button-primary">Manage billing &amp; invoices</button></form><p class="qrb-help">Open Stripe’s secure portal to update payment details, change or cancel your plan, and download invoices.</p>';
        } else {
            $html .= '<div class="qrb-actions">' . $this->upgradeForm('pro') . $this->upgradeForm('business') . '</div>';
        }
        $html .= '</section>' . $this->usageCard() . '<section class="qrb-card"><h2>Compare plans</h2>' . $this->planCards(true) . '</section>';
        return $html;
    }

    private function onboarding(): string { $step = sanitize_key((string) ($_GET['step'] ?? $this->workspaces->current()->onboardingStep ?: 'plan')); if ($step === 'workspace') { $notice = !empty($_GET['billing']) ? '<p class="qrb-alert">Stripe test keys are not configured yet, so this plan has been selected in local prototype mode.</p>' : ''; return '<h1>Create workspace</h1>' . $notice . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_onboarding', 'nonce', true, false) . '<input type="hidden" name="onboarding_action" value="workspace"><label>Workspace name<input name="workspace_name" value="' . esc_attr($this->workspaces->current()->name) . '"></label><label>Website<input name="website_url" type="url"></label><label>Intended use<select name="intended_use"><option value="marketing">Marketing campaigns</option><option value="wifi">WiFi access</option><option value="events">Events</option><option value="other">Other</option></select></label><button class="qrb-button qrb-button-primary">Continue</button></form>'; } if ($step === 'first_qr') { return '<h1>Create your first QR</h1><div class="qrb-card"><div class="qrb-actions"><a class="qrb-button" href="/app/qr/new?type=dynamic_url&studio=1">Dynamic Website QR</a><a class="qrb-button" href="/app/qr/new?type=static_url&studio=1">Static Website QR</a><a class="qrb-button" href="/app/qr/new?type=wifi&studio=1">WiFi QR</a></div><form method="post">' . wp_nonce_field('qrbuzz_onboarding', 'nonce', true, false) . '<input type="hidden" name="onboarding_action" value="complete"><button class="qrb-button qrb-button-primary">Skip for now</button></form></div>'; } return '<h1>Choose plan</h1>' . (!empty($_GET['error']) ? '<p class="qrb-alert">Stripe checkout is not available. You can still choose a paid plan in local prototype mode while test keys are configured.</p>' : '') . $this->planCards(true); }
    private function pricing(): string {
        return '<h1>Plans</h1><p>Start free and upgrade when you need more capacity, reporting or control.</p>' . $this->planCards(false);
    }

    private function planCards(bool $authenticated): string {
        $html = '<div class="qrb-plan-grid">';
        $currentPlanKey = $authenticated ? $this->entitlements->effectivePlanKey() : 'free';
        foreach ($this->billingPlans->plans() as $key => $plan) {
            $html .= '<article class="qrb-card"><h2>' . esc_html($plan['label']) . '</h2><p><strong>' . esc_html($plan['display_price']) . '</strong>' . ($plan['interval'] ? ' / ' . esc_html($plan['interval']) : '') . '</p><p>' . esc_html($plan['description']) . '</p><ul>';
            foreach ($plan['features'] as $feature) { $html .= '<li>' . esc_html($feature) . '</li>'; }
            $html .= '</ul>';
            if ($authenticated) {
                $html .= $this->planCardAction($key, $currentPlanKey);
            } else {
                $url = $key === 'free' ? '/register' : '/register?plan=' . rawurlencode($key);
                $label = $key === 'free' ? 'Get started free' : 'Choose ' . $plan['label'];
                $html .= '<a class="qrb-button qrb-button-primary" href="' . esc_url($url) . '">' . esc_html($label) . '</a>';
            }
            $html .= '</article>';
        }
        return $html . '</div>';
    }

    private function planCardAction(string $targetPlanKey, string $currentPlanKey): string {
        $ranks = ['free' => 0, 'pro' => 1, 'business' => 2];
        $targetPlanKey = isset($ranks[$targetPlanKey]) ? $targetPlanKey : 'free';
        $currentPlanKey = isset($ranks[$currentPlanKey]) ? $currentPlanKey : 'free';
        $existing = $this->subscriptions->forWorkspace($this->workspaces->id());

        if ($targetPlanKey === $currentPlanKey) {
            if ($existing && !empty($existing->stripe_customer_id)) {
                return '<form method="post">' . wp_nonce_field('qrbuzz_app_billing', 'nonce', true, false) . '<input type="hidden" name="billing_action" value="portal"><button class="qrb-button">Manage plan</button></form>';
            }
            return '<button class="qrb-button" type="button" disabled aria-disabled="true">Manage plan</button>';
        }

        $direction = $ranks[$targetPlanKey] > $ranks[$currentPlanKey] ? 'Upgrade' : 'Downgrade';
        $label = $direction . ' to ' . $this->plans->label($targetPlanKey);
        if ($existing && !empty($existing->stripe_customer_id)) {
            return '<form method="post">' . wp_nonce_field('qrbuzz_app_billing', 'nonce', true, false) . '<input type="hidden" name="billing_action" value="portal"><button class="qrb-button">' . esc_html($label) . '</button></form>';
        }
        if ($targetPlanKey === 'free') {
            return '<button class="qrb-button" type="button" disabled aria-disabled="true">' . esc_html($label) . '</button>';
        }

        return $this->upgradeForm($targetPlanKey, $label);
    }

    private function upgradeForm(string $planKey, string $buttonLabel = ''): string {
        $planKey = in_array($planKey, ['pro', 'business'], true) ? $planKey : 'pro';
        $existing = $this->subscriptions->forWorkspace($this->workspaces->id());
        if ($existing && !empty($existing->stripe_customer_id)) {
            return '<form method="post">' . wp_nonce_field('qrbuzz_app_billing', 'nonce', true, false) . '<input type="hidden" name="billing_action" value="portal"><button class="qrb-button">Manage plan in Stripe</button></form>';
        }
        $returnTo = sanitize_key((string) ($_GET['return_to'] ?? 'billing'));
        if (!in_array($returnTo, ['billing', 'analytics'], true)) { $returnTo = 'billing'; }
        $buttonLabel = $buttonLabel !== '' ? $buttonLabel : 'Upgrade to ' . $this->plans->label($planKey);
        return '<form method="post">' . wp_nonce_field('qrbuzz_app_billing', 'nonce', true, false) . '<input type="hidden" name="billing_action" value="checkout"><input type="hidden" name="plan_key" value="' . esc_attr($planKey) . '"><input type="hidden" name="return_to" value="' . esc_attr($returnTo) . '"><button class="qrb-button qrb-button-primary">' . esc_html($buttonLabel) . '</button></form>';
    }

    private function registerForm(): string {
        $notice = '';
        $plan = sanitize_key((string) ($_GET['plan'] ?? 'free'));
        if (!in_array($plan, ['free', 'pro', 'business'], true)) { $plan = 'free'; }
        if ($plan !== 'free') { $notice .= '<p class="qrb-alert qrb-alert-success">Create your account first, then we will take you to Billing to upgrade to ' . esc_html($this->plans->label($plan)) . '.</p>'; }
        if (isset($_GET['error'])) {
            $error = sanitize_key((string) $_GET['error']);
            $messages = [
                'rate' => 'Too many registration attempts. Please wait a few minutes and try again.',
                'weak_password' => 'Your password does not meet the requirements below.',
                'registration_failed' => 'We could not create your account. Check that every field is complete, the passwords match, and you have accepted the Terms and Privacy Policy.',
                'google_collision' => 'An account already uses that email address. Log in to link your Google account.',
            ];
            $notice = '<p class="qrb-alert" role="alert">' . esc_html($messages[$error] ?? 'We could not create your account. Please check your details and try again.') . '</p>';
        }
        return '<h1>Create your QR Buzz account</h1>' . $notice . $this->googleButton('register') . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_register', 'nonce', true, false) . '<input type="hidden" name="plan" value="' . esc_attr($plan) . '">' . '<label>First name<input name="first_name" autocomplete="given-name" required></label><label>Last name<input name="last_name" autocomplete="family-name" required></label><label>Email<input type="email" name="email" autocomplete="email" required></label>' . $this->passwordRequirements() . '<label>Password<input type="password" name="password" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="password_confirm" autocomplete="new-password" required></label><label><input type="checkbox" name="terms" value="1" required> I accept the Terms and Privacy Policy</label><button class="qrb-button qrb-button-primary">Create account</button></form>';
    }
    private function loginForm(): string {
        $googleLink = sanitize_text_field((string) ($_GET['google_link'] ?? ''));
        $notice = '';
        if ($googleLink !== '') { $notice .= '<p class="qrb-alert">Log in with your existing QR Buzz password to link Google to this account.</p>'; }
        if (!empty($_GET['reset'])) { $notice .= '<p class="qrb-alert qrb-alert-success">Your password has been updated. You can log in with your new password now.</p>'; }
        if (isset($_GET['error'])) {
            $error = sanitize_key((string) $_GET['error']);
            $message = $error === 'rate' ? 'Too many login attempts. Please wait a few minutes and try again.' : 'Login failed. Please check your email or username and password.';
            $notice .= '<p class="qrb-alert">' . esc_html($message) . '</p>';
        }
        $hidden = $googleLink !== '' ? '<input type="hidden" name="google_link" value="' . esc_attr($googleLink) . '">' : '';
        return '<h1>Log in</h1>' . $notice . $this->googleButton('login') . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_login', 'nonce', true, false) . $hidden . '<label>Email or username<input name="login" required></label><label>Password<input type="password" name="password" required></label><label><input type="checkbox" name="remember" value="1"> Remember me</label><button class="qrb-button qrb-button-primary">Log in</button><p><a href="/forgot-password">Forgot password?</a></p></form>';
    }
    private function passwordPage(): string {
        if (($_GET['action'] ?? '') === 'reset') {
            $notice = '';
            if (isset($_GET['error'])) {
                $error = sanitize_key((string) $_GET['error']);
                $messages = [
                    'link' => 'This password reset link is invalid or has expired. Request a new link and try again.',
                    'required' => 'Enter and confirm your new password.',
                    'mismatch' => 'The passwords do not match. Enter the same password in both fields.',
                    'weak' => 'Your new password does not meet the requirements below.',
                ];
                $notice = '<p class="qrb-alert" role="alert">' . esc_html($messages[$error] ?? 'We could not reset your password. Check the details below and try again.') . '</p>';
            }
            return '<h1>Reset password</h1>' . $notice . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_password_reset', 'nonce', true, false) . '<input type="hidden" name="mode" value="reset"><input type="hidden" name="key" value="' . esc_attr((string) ($_GET['key'] ?? '')) . '"><input type="hidden" name="login" value="' . esc_attr(rawurldecode((string) ($_GET['login'] ?? ''))) . '">' . $this->passwordRequirements() . '<label>New password<input type="password" name="password" autocomplete="new-password" required></label><label>Confirm password<input type="password" name="password_confirm" autocomplete="new-password" required></label><button class="qrb-button qrb-button-primary">Reset password</button></form>';
        }
        $notice = !empty($_GET['sent']) ? '<p class="qrb-alert qrb-alert-success">If that account exists, a password reset email is on its way.</p>' : '';
        if (!empty($_GET['error'])) { $notice .= '<p class="qrb-alert" role="alert">We could not send the reset email. Check the email address or username and try again.</p>'; }
        return '<h1>Forgot password</h1>' . $notice . '<form class="qrb-card qrb-form" method="post">' . wp_nonce_field('qrbuzz_password_request', 'nonce', true, false) . '<input type="hidden" name="mode" value="request"><label>Email or username<input name="login" autocomplete="username" required></label><button class="qrb-button qrb-button-primary">Send reset link</button></form>';
    }

    private function passwordRequirements(): string {
        return '<p class="qrb-help">Password requirements: at least 10 characters, including one uppercase letter, one lowercase letter and one number.</p>';
    }

    private function verifyEmail(): void { $userId = absint($_GET['user'] ?? 0); $token = (string) ($_GET['token'] ?? ''); $ok = $userId && $token && $this->profiles->verifyByToken($userId, $token); $this->page('Verify email', '<h1>' . ($ok ? 'Email verified' : 'Verification failed') . '</h1><p><a class="qrb-button" href="/app/dashboard">Continue</a></p>'); }
    private function resendVerification(): void {
        if (!is_user_logged_in()) { $this->redirect('/login?error=session'); }
        $userId = get_current_user_id();
        $key = 'qrbuzz_verify_resend_' . $userId;
        if (get_transient($key)) { $this->redirect('/app/settings/account?verify=rate'); }
        set_transient($key, 1, 10 * MINUTE_IN_SECONDS);
        $this->auth->sendVerification($userId);
        $this->redirect('/app/settings/account?verify=sent');
    }
    private function startGoogle(): void {
        $intent = sanitize_key((string) ($_GET['intent'] ?? 'login'));
        if (!in_array($intent, ['login', 'register'], true)) { $intent = 'login'; }
        $url = $this->google->authorizationUrl($intent);
        if ($url === '') { $this->redirect('/login?error=google_config'); }
        wp_redirect($url);
        exit;
    }
    private function finishGoogle(): void {
        $identity = $this->google->authenticate((string) ($_GET['code'] ?? ''), (string) ($_GET['state'] ?? ''));
        if (is_wp_error($identity)) { $this->redirect('/login?error=' . rawurlencode($identity->get_error_code())); }
        $providerSubject = (string) ($identity['sub'] ?? '');
        $email = sanitize_email((string) ($identity['email'] ?? ''));
        if ($providerSubject === '' || !is_email($email) || empty($identity['email_verified'])) { $this->redirect('/login?error=google_unverified'); }
        $existingIdentity = $this->identities->find('google', $providerSubject);
        if ($existingIdentity) {
            $this->identities->markLogin((int) $existingIdentity->id);
            $this->profiles->markEmailVerified((int) $existingIdentity->user_id);
            $this->auth->establishSession((int) $existingIdentity->user_id);
            $this->redirectAfterLogin();
        }
        $matchingUserId = (int) email_exists($email);
        if ($matchingUserId > 0) {
            $token = wp_generate_password(32, false, false);
            set_transient('qrbuzz_google_link_' . hash('sha256', $token), $identity, 10 * MINUTE_IN_SECONDS);
            $this->redirect('/login?google_link=' . rawurlencode($token));
        }
        $userId = $this->auth->registerGoogle($identity);
        if (is_wp_error($userId)) { $this->redirect('/register?error=' . rawurlencode($userId->get_error_code())); }
        $this->identities->link((int) $userId, 'google', $providerSubject, $email);
        $this->auth->establishSession((int) $userId);
        $this->redirectAfterLogin();
    }
    private function completeGoogleLink(int $userId, string $token): void {
        $key = 'qrbuzz_google_link_' . hash('sha256', $token);
        $identity = get_transient($key);
        delete_transient($key);
        $user = get_user_by('id', $userId);
        if (!is_array($identity) || !$user || strtolower((string) $user->user_email) !== strtolower((string) ($identity['email'] ?? ''))) {
            wp_logout();
            $this->redirect('/login?error=google_link');
        }
        if (!$this->identities->link($userId, 'google', (string) ($identity['sub'] ?? ''), (string) ($identity['email'] ?? ''))) {
            $this->redirect('/login?error=google_link');
        }
        $this->profiles->markEmailVerified($userId);
    }
    private function googleButton(string $intent): string { return $this->google->configured() ? '<p><a class="qrb-button qrb-button-primary" href="' . esc_url(home_url('/auth/google?intent=' . rawurlencode($intent))) . '">Continue with Google</a></p>' : ''; }
    private function requireApp(): void { if (!is_user_logged_in()) { $this->redirect('/login?redirect=' . rawurlencode(home_url('/' . $this->path()))); } }
    private function redirectAfterLogin(): void { $w = $this->workspaces->current(); if (!$w->onboardingComplete()) { $this->completeOnboardingAndRedirect($w); } $this->redirect('/app/dashboard'); }
    private function completeOnboardingAndRedirect($workspace = null): void { $workspace = $workspace ?: $this->workspaces->current(); if (!$workspace->onboardingComplete()) { $this->completeOnboarding(); (new \QRBuzz\Platform\PlatformEventRepository())->record('onboarding_completed', ['user_id' => get_current_user_id(), 'workspace_id' => $workspace->id]); } $this->redirect('/app/dashboard?welcome=1'); }
    private function completeOnboarding(): void { $this->workspaceRepo->updateOnboarding($this->workspaces->id(), 'complete', 'complete'); $this->profiles->updateOnboarding(get_current_user_id(), 'complete', 'complete'); }
    private function payloadFromPost(string $type): array { $payload = isset($_POST['payload']) && is_array($_POST['payload']) ? wp_unslash($_POST['payload']) : []; $clean = []; foreach ($payload as $key => $value) { $clean[sanitize_key((string) $key)] = is_scalar($value) ? sanitize_textarea_field((string) $value) : ''; } if ($type === 'dynamic_url') { $clean['destination_url'] = esc_url_raw($clean['destination_url'] ?? ''); } if ($type === 'static_url') { $clean['url'] = esc_url_raw($clean['url'] ?? $clean['destination_url'] ?? ''); } if ($type === 'vcard' && isset($clean['website'])) { $clean['website'] = esc_url_raw($clean['website']); } if ($type === 'email' && isset($clean['recipient'])) { $clean['recipient'] = sanitize_email($clean['recipient']); } $clean['hidden'] = !empty($payload['hidden']) ? '1' : ''; return $clean; }
    private function smartRuleDataFromPost(): array { $status = sanitize_key((string) ($_POST['status'] ?? 'active')); return ['name' => sanitize_text_field((string) ($_POST['name'] ?? '')), 'priority' => absint($_POST['priority'] ?? 10), 'status' => $status === 'inactive' ? 'inactive' : 'active', 'destination_url' => esc_url_raw((string) ($_POST['destination_url'] ?? '')), 'starts_at' => $this->optionalDateTime('starts_at'), 'ends_at' => $this->optionalDateTime('ends_at'), 'days_of_week' => isset($_POST['days_of_week']) && is_array($_POST['days_of_week']) ? array_map('absint', wp_unslash($_POST['days_of_week'])) : preg_replace('/[^0-9,]/', '', sanitize_text_field((string) ($_POST['days_of_week'] ?? ''))), 'time_start' => sanitize_text_field((string) ($_POST['time_start'] ?? '')), 'time_end' => sanitize_text_field((string) ($_POST['time_end'] ?? ''))]; }
    private function usageCard(): string { $e = $this->entitlements->summary(); return '<section class="qrb-card"><h2>Usage</h2>' . $this->usageRow('QR assets', (int) $e['usage']['qr_assets'], $e['limits']['qr_assets']) . $this->usageRow('Campaigns', (int) $e['usage']['campaigns'], $e['limits']['campaigns']) . '</section>'; }
    private function usageRow(string $label, int $used, $limit): string { $limitLabel = $limit === null ? 'Unlimited' : (string) $limit; $percent = $limit === null || (int) $limit <= 0 ? 100 : min(100, (int) round(($used / (int) $limit) * 100)); $remaining = $limit === null ? 'Unlimited remaining' : max(0, (int) $limit - $used) . ' remaining'; return '<div class="qrb-card-meta"><span><strong>' . esc_html($label) . '</strong></span><span>' . esc_html((string) $used) . ' / ' . esc_html($limitLabel) . ' · ' . esc_html($remaining) . '</span><div class="qrb-usage-bar" aria-hidden="true"><span style="width:' . esc_attr((string) $percent) . '%"></span></div></div>'; }
    private function tip(string $key, string $message): string { return '<div class="qrb-tip" data-qrb-dismissible="' . esc_attr($key) . '"><p>' . esc_html($message) . '</p><button type="button" class="qrb-toast-close" data-qrb-dismiss aria-label="Dismiss tip">Dismiss</button></div>'; }
    private function tooltip(string $message): string { return '<span class="qrb-tooltip-trigger" tabindex="0" role="tooltip" data-qrb-tooltip="' . esc_attr($message) . '" aria-label="' . esc_attr($message) . '">?</span>'; }
    private function qrHealth(QRCode $qr): array {
        $status = $qr->effectiveStatus();
        if ($status === 'expired') { return ['attention', 'Needs attention', 'This QR has expired and will no longer use the normal destination.']; }
        if ($status === 'paused') { return ['quiet', 'Paused', 'This QR is intentionally paused.']; }
        if ($qr->isTrackable() && empty($qr->fallbackUrl)) { return ['quiet', 'Quiet', 'No fallback destination is configured yet.']; }
        if ($qr->isTrackable() && (int) $qr->scanCount === 0) { return ['quiet', 'Quiet', 'Ready to scan, but no activity has arrived yet.']; }
        return ['healthy', 'Healthy', 'Configuration looks ready.'];
    }
    private function qrHealthBlock(array $health): string { return '<div class="qrb-health qrb-health-' . esc_attr($health[0]) . ' qrb-qr-card-health" title="' . esc_attr($health[2]) . '"><strong>' . esc_html($health[1]) . ' ' . $this->tooltip('QR health is a simple deterministic check of status, fallback configuration and recent activity.') . '</strong><small>' . esc_html($health[2]) . '</small></div>'; }
    private function analyticsObservation(int $total, int $today, int $week): string {
        if ($total <= 0) { return '<p class="qrb-tip">Analytics begin after this dynamic QR code receives its first scan.</p>'; }
        if ($today > 0 && $total === $today) { return '<p class="qrb-tip">This QR received its first scan today.</p>'; }
        if ($week > 0) { return '<p class="qrb-tip">This QR has scan activity in the last 7 days.</p>'; }
        return '<p class="qrb-tip">This QR has scan history, but no scans in the current 7 day window.</p>';
    }
    private function metrics(array $metrics): string { $html = '<div class="qrb-metrics">'; foreach ($metrics as $m) { $html .= '<div class="qrb-card qrb-metric"><span>' . esc_html((string) $m[0]) . '</span><strong>' . esc_html((string) $m[1]) . '</strong>' . (isset($m[2]) ? '<small>' . esc_html((string) $m[2]) . '</small>' : '') . '</div>'; } return $html . '</div>'; }
    private function metricsCompact(array $metrics): string { $html = '<div class="qrb-metrics qrb-metrics-compact">'; foreach ($metrics as $m) { $html .= '<div class="qrb-card qrb-metric"><span>' . esc_html((string) $m[0]) . '</span><strong>' . esc_html((string) $m[1]) . '</strong>' . (isset($m[2]) ? '<small>' . esc_html((string) $m[2]) . '</small>' : '') . '</div>'; } return $html . '</div>'; }
    private function studioSection(string $title, string $body, string $key = ''): string { $key = $key ?: sanitize_title($title); return '<details class="qrb-studio-section" data-qrb-accordion="' . esc_attr($key) . '" open><summary>' . esc_html($title) . '</summary><div class="qrb-studio-section-body">' . $body . '</div></details>'; }
    private function pageHeader(string $title, string $description, string $actions = ''): string { return '<div class="qrb-page-header"><div><h1>' . esc_html($title) . '</h1><p>' . esc_html($description) . '</p></div><div class="qrb-actions">' . $actions . '</div></div>'; }
    private function emptyState(string $title, string $description, string $url, string $label): string { return '<section class="qrb-empty"><h2>' . esc_html($title) . '</h2><p>' . esc_html($description) . '</p><a class="qrb-button qrb-button-primary" href="' . esc_url(home_url($url)) . '">' . esc_html($label) . '</a></section>'; }
    private function statusBadge(string $status): string { $class = $status === 'active' ? 'qrb-badge-success' : ($status === 'expired' ? 'qrb-badge-danger' : ($status === 'scheduled' ? 'qrb-badge-info' : 'qrb-badge-warning')); return '<span class="qrb-badge ' . esc_attr($class) . '">' . esc_html(ucfirst($status)) . '</span>'; }
    private function libraryFilters(string $search, int $campaignId, string $typeFilter, string $statusFilter): string {
        $html = '<form class="qrb-filterbar" method="get"><label>Search<input name="s" value="' . esc_attr($search) . '" placeholder="Search by name"></label><label>Kind<select name="type"><option value="">All QR codes</option><option value="dynamic" ' . selected($typeFilter, 'dynamic', false) . '>Dynamic</option><option value="static" ' . selected($typeFilter, 'static', false) . '>Static</option>';
        foreach ($this->types->all() as $type => $meta) { $html .= '<option value="' . esc_attr($type) . '" ' . selected($typeFilter, $type, false) . '>' . esc_html($meta['label']) . '</option>'; }
        $html .= '</select></label><label>Campaign<select name="campaign"><option value="0">All campaigns</option>';
        foreach ($this->campaigns->active() as $campaign) { $html .= '<option value="' . esc_attr($campaign->id) . '" ' . selected($campaignId, $campaign->id, false) . '>' . esc_html($campaign->name) . '</option>'; }
        $html .= '</select></label><label>Status<select name="status"><option value="">Any status</option><option value="active" ' . selected($statusFilter, 'active', false) . '>Active</option><option value="paused" ' . selected($statusFilter, 'paused', false) . '>Paused</option><option value="expired" ' . selected($statusFilter, 'expired', false) . '>Expired</option></select></label><button class="qrb-button qrb-button-primary">Apply</button><a class="qrb-button" href="/app/library">Clear</a></form>';
        return $html;
    }
    private function libraryTable(array $rows): string { $html = '<div class="qrb-table-wrap"><table class="qrb-table"><thead><tr><th>QR</th><th>Type</th><th>Campaign</th><th>Status</th><th>Scans</th><th>Last scan</th><th>Updated</th><th>Actions</th></tr></thead><tbody>'; foreach ($rows as $qr) { $html .= '<tr><td><a href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '">' . esc_html($qr->name) . '</a><br><small>' . esc_html($qr->shortcode) . '</small></td><td>' . esc_html($this->types->label($qr->type)) . '</td><td>' . esc_html($qr->campaignName ?: 'Unassigned') . '</td><td>' . $this->statusBadge($qr->effectiveStatus()) . '</td><td>' . esc_html((string) $qr->scanCount) . '</td><td>' . esc_html($qr->lastScan ?: '-') . '</td><td>' . esc_html($qr->updatedAt) . '</td><td><div class="qrb-actions"><a class="qrb-button" href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '">View</a><a class="qrb-button" href="' . esc_url($this->studioUrl($qr->type, $qr->id)) . '">Edit</a></div></td></tr>'; } return $html . '</tbody></table></div>'; }
    private function qrCard(QRCode $qr): string { $payload = $this->payloads->payloadForQrCode($qr); try { $preview = '<img class="qrb-preview" src="' . esc_attr($this->generator->generatePngDataUri($payload, 160, QRDesignSettings::fromQrCode($qr))) . '" alt="QR preview">'; } catch (\Throwable $e) { $preview = '<p>Preview unavailable.</p>'; } $status = $qr->effectiveStatus(); $health = $this->qrHealth($qr); $delete = '<form class="qrb-qr-card-delete" method="post" action="' . esc_url(home_url('/app/library')) . '">' . wp_nonce_field('qrbuzz_app_delete_qr_' . $qr->id, 'nonce', true, false) . '<input type="hidden" name="qr_id" value="' . esc_attr((string) $qr->id) . '"><button class="qrb-button qrb-button-quiet qrb-icon-button" type="submit" aria-label="Delete ' . esc_attr($qr->name) . '" title="Delete QR code" data-confirm="Delete ' . esc_attr($qr->name) . '? This will permanently delete the QR code and its scan history.">' . $this->icon('trash') . '</button></form>'; $actions = '<a class="qrb-button qrb-button-quiet qrb-icon-button" href="' . esc_url($this->studioUrl($qr->type, $qr->id)) . '" title="Edit" aria-label="Edit ' . esc_attr($qr->name) . '">' . $this->icon('edit') . '</a><a class="qrb-button qrb-button-quiet qrb-icon-button" href="' . esc_url($this->downloadUrl($qr->id, 'png')) . '" title="Download PNG" aria-label="Download PNG for ' . esc_attr($qr->name) . '">' . $this->icon('download') . '</a><a class="qrb-button qrb-button-quiet qrb-icon-button" href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '" title="Details" aria-label="View details for ' . esc_attr($qr->name) . '">' . $this->icon('eye') . '</a>'; if ($qr->isTrackable()) { $actions .= '<a class="qrb-button qrb-button-quiet qrb-icon-button" href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '#analytics" title="Analytics" aria-label="View analytics for ' . esc_attr($qr->name) . '">' . $this->icon('chart') . '</a><a class="qrb-button qrb-button-quiet qrb-icon-button" href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '#smart-destinations" title="Smart Destinations" aria-label="View Smart Destinations for ' . esc_attr($qr->name) . '">' . $this->icon('route') . '</a>'; } return '<article class="qrb-card qrb-qr-card" data-qrb-status="' . esc_attr($status) . '"><div class="qrb-qr-card-preview">' . $preview . '</div><div><h2><a href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '">' . esc_html($qr->name) . '</a></h2><div class="qrb-card-meta"><span>Type: ' . esc_html($this->types->label($qr->type)) . '</span><span>Campaign: ' . esc_html($qr->campaignName ?: 'Unassigned') . '</span><span>Scans: ' . esc_html((string) $qr->scanCount) . '</span></div>' . $this->qrHealthBlock($health) . '</div><div class="qrb-card-actions-primary">' . $this->statusBadge($status) . '<div class="qrb-card-actions-secondary">' . $actions . $delete . '</div></div></article>'; }
    private function assetCard(\QRBuzz\Models\Asset $asset): string { return '<article class="qrb-asset-card" data-qrb-asset-id="' . esc_attr((string) $asset->id) . '" data-qrb-asset-url="' . esc_url($asset->url) . '" data-qrb-asset-name="' . esc_attr($asset->displayName) . '" data-qrb-asset-usage="' . esc_attr((string) $asset->usageCount) . '"><div class="qrb-asset-thumb"><img src="' . esc_url($asset->url) . '" alt=""></div><div><h3>' . esc_html($asset->displayName) . '</h3><p>' . esc_html($asset->width . ' x ' . $asset->height . ' px') . ' &middot; ' . esc_html(size_format($asset->fileSize)) . '</p><p>' . esc_html($asset->usageCount > 0 ? $asset->usageCount . ' QR code' . ($asset->usageCount === 1 ? '' : 's') : 'Unused') . '</p></div><div class="qrb-asset-actions"><button type="button" class="qrb-button qrb-icon-button" data-qrb-asset-rename title="Rename" aria-label="Rename ' . esc_attr($asset->displayName) . '">' . $this->icon('edit') . '</button><button type="button" class="qrb-button qrb-icon-button" data-qrb-asset-delete title="Delete" aria-label="Delete ' . esc_attr($asset->displayName) . '">' . $this->icon('trash') . '</button></div></article>'; }
    private function recentQrList(array $rows): string { if (!$rows) { return '<p>No QR codes yet.</p>'; } $html = '<ul class="qrb-activity-list">'; foreach ($rows as $qr) { $html .= '<li><span><a href="' . esc_url(home_url('/app/qr/' . $qr->id)) . '">' . esc_html($qr->name) . '</a><br><small>' . esc_html($this->types->label($qr->type)) . '</small></span><span>' . esc_html($qr->createdAt) . '</span></li>'; } return $html . '</ul>'; }
    private function recentScanList(array $rows): string { if (!$rows) { return '<p>No scan activity yet.</p>'; } $html = '<ul class="qrb-activity-list">'; foreach ($rows as $row) { $html .= '<li><span>' . esc_html((string) $row->qr_name) . ' scanned<br><small>' . esc_html($row->referrer ?: 'Direct / unknown') . '</small></span><span>' . esc_html((string) $row->scanned_at) . '</span></li>'; } return $html . '</ul>'; }
    private function settingsNav(string $active): string { $items = ['account' => ['Account', '/app/settings/account'], 'workspace' => ['Workspace & Brand Kit', '/app/settings/workspace'], 'billing' => ['Billing', '/app/settings/billing']]; $html = '<nav class="qrb-actions qrb-card" aria-label="Settings sections">'; foreach ($items as $key => [$label, $url]) { $html .= '<a class="qrb-button ' . ($active === $key ? 'qrb-button-primary' : '') . '" href="' . esc_url(home_url($url)) . '">' . esc_html($label) . '</a>'; } return $html . '</nav>'; }
    private function greeting(): string { $hour = (int) wp_date('G', current_time('timestamp')); if ($hour < 12) { return 'Good morning'; } if ($hour < 18) { return 'Good afternoon'; } return 'Good evening'; }
    private function hero(string $title, string $action = ''): string { return '<section class="qrb-hero"><h1>' . $title . '</h1>' . $action . '</section>'; }
    private function filename(string $name, string $shortcode, string $extension): string { $safe = sanitize_title($name); if ($safe === '') { $safe = strtolower($shortcode); } return $safe . '-' . strtolower($shortcode) . '.' . $extension; }
    private function studioUrl(string $type, int $id = 0): string { return $id > 0 ? home_url('/app/qr/' . $id . '/studio') : home_url('/app/qr/new?type=' . rawurlencode($type) . '&studio=1'); }
    private function downloadUrl(int $id, string $format): string { $format = $format === 'svg' ? 'svg' : 'png'; return wp_nonce_url(admin_url('admin-post.php?action=qrbuzz_download_' . $format . '&qr_id=' . $id), 'qrbuzz_download_qr_' . $id); }
    private function input(string $label, string $name, string $value, string $type = 'text'): string { return '<label>' . esc_html($label) . '<input type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '"></label>'; }
    private function textarea(string $label, string $name, string $value): string { return '<label>' . esc_html($label) . '<textarea name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea></label>'; }
    private function select(string $label, string $name, string $selected, array $options): string { $html = '<label>' . esc_html($label) . '<select name="' . esc_attr($name) . '">'; foreach ($options as $value => $optionLabel) { $html .= '<option value="' . esc_attr((string) $value) . '" ' . selected($selected, $value, false) . '>' . esc_html((string) $optionLabel) . '</option>'; } return $html . '</select></label>'; }
    private function colorControl(string $label, string $name, string $value): string { $value = QRDesignSettings::sanitizeColor($value, '#000000'); return '<label>' . esc_html($label) . '<span class="qrb-color-control"><input type="color" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" data-qrb-color-input><input type="text" value="' . esc_attr($value) . '" pattern="#[0-9a-fA-F]{6}" maxlength="7" data-qrb-color-text aria-label="' . esc_attr($label . ' hex colour') . '"></span></label>'; }
    private function brandKitDefaultsSection(): string {
        if (!$this->entitlements->allows('brand_kit')) {
            return '<details class="qrb-studio-section qrb-brand-kit-save" data-qrb-accordion="brand-kit-defaults"><summary>Brand Kit defaults 🔒</summary><div class="qrb-studio-section-body"><p>Save reusable QR design defaults with Pro or Business.</p><a class="qrb-button qrb-button-primary" href="' . esc_url(home_url('/app/settings/billing?upgrade=pro')) . '">Upgrade</a></div></details>';
        }
        return '<details class="qrb-studio-section qrb-brand-kit-save" data-qrb-accordion="brand-kit-defaults"><summary>Brand Kit defaults</summary><div class="qrb-studio-section-body"><label class="qrb-inline-check"><input type="checkbox" name="set_as_brand_kit" value="1"><span>Set as Brand Kit defaults</span></label><p class="qrb-help">Save this QR design as the starting style for new QR codes in this workspace.</p></div></details>';
    }

    private function assetPickerModal(): string { return '<div class="qrb-asset-modal" data-qrb-asset-modal hidden><div class="qrb-asset-modal-panel" role="dialog" aria-modal="true" aria-label="Choose logo asset"><div class="qrb-page-head"><div><h2>Choose logo</h2><p>Upload or select an asset for this QR code.</p></div><button type="button" class="qrb-button qrb-icon-button" data-qrb-asset-modal-close aria-label="Close asset picker">' . $this->icon('chevron-down') . '</button></div><label class="qrb-dropzone"><input type="file" accept="image/png,image/jpeg,image/webp" data-qrb-picker-upload><span>Drop an image here or browse</span></label><p class="qrb-preview-status" data-qrb-picker-status></p><div class="qrb-asset-picker-grid" data-qrb-asset-picker-grid></div><div class="qrb-actions"><button type="button" class="qrb-button" data-qrb-asset-modal-close>Cancel</button></div></div></div>'; }
    private function themeOptions(): array { $options = []; foreach ($this->themes->all() as $key => $theme) { $options[(string) $key] = (string) $theme['label']; } return $options; }
    private function captionFontOptions(): array { return ['arial' => 'Arial', 'georgia' => 'Georgia', 'verdana' => 'Verdana', 'trebuchet' => 'Trebuchet MS', 'courier' => 'Courier New']; }
    private function dayCheckboxes(array $selected): string { $labels = [1 => 'M', 2 => 'T', 3 => 'W', 4 => 'T', 5 => 'F', 6 => 'S', 7 => 'S']; $html = '<fieldset class="qrb-day-picker qrb-form-wide"><legend>Days of week</legend>'; foreach ($labels as $value => $label) { $html .= '<label><input type="checkbox" name="days_of_week[]" value="' . esc_attr((string) $value) . '" ' . checked(in_array($value, $selected, true), true, false) . '><span>' . esc_html($label) . '</span></label>'; } return $html . '</fieldset>'; }
    private function logoField(QRDesignSettings $design): string {
        $allowed = $this->entitlements->allows('logo_embedding');
        $asset = $design->logoAssetId ? $this->assets->find($design->logoAssetId) : null;
        $asset = $asset ? $this->assetStorage->withUrl($asset) : null;
        $legacy = !$asset && $design->logoAttachmentId ? wp_get_attachment_image($design->logoAttachmentId, 'thumbnail') : '';
        $image = $asset ? '<img src="' . esc_url($asset->url) . '" alt="">' : $legacy;
        $hasLogo = $asset || $legacy;
        $chooseLabel = $allowed ? 'Choose from Asset Library' : 'Add your logo 🔒';
        $upgrade = $allowed ? '' : '<span class="qrb-help">Add your logo to branded QR codes with Pro or Business. <a href="' . esc_url(home_url('/app/settings/billing?upgrade=pro')) . '">Upgrade</a></span>';
        $legacyNote = !$allowed && $hasLogo ? '<span class="qrb-help">This existing logo is preserved. Upgrade to change or remove it.</span>' : '';
        return '<label>Logo<input type="hidden" class="qrb-logo-asset-id" name="logo_asset_id" value="' . esc_attr((string) $design->logoAssetId) . '"><input type="hidden" class="qrb-logo-id" name="logo_attachment_id" value="' . esc_attr((string) $design->logoAttachmentId) . '"><span class="qrb-logo-actions"><button type="button" class="qrb-button qrb-select-logo" ' . disabled(!$allowed, true, false) . '>' . esc_html($chooseLabel) . '</button><a class="qrb-button" href="' . esc_url(home_url('/app/assets')) . '">Manage assets</a><button type="button" class="qrb-button qrb-remove-logo" ' . disabled(!$allowed || !$hasLogo, true, false) . '>Remove logo</button></span><span class="qrb-logo-preview">' . wp_kses_post($image) . '</span>' . $upgrade . $legacyNote . '<span class="qrb-help">PNG, JPG and WebP logo artwork is stored in your QR Buzz Asset Library. Non-square logos are fitted without stretching.</span></label>' . $this->assetPickerModal();
    }

    private function newDesignDefaults(): QRDesignSettings { $design = $this->brandKit->designDefaults(); $design->logoAttachmentId = 0; $design->logoAssetId = 0; return $design; }
    private function optionalUrl(string $field): ?string { $url = esc_url_raw((string) ($_POST[$field] ?? '')); return trim($url) === '' ? null : $url; }
    private function optionalDateTime(string $field): ?string { $date = sanitize_text_field((string) ($_POST[$field . '_date'] ?? '')); $time = sanitize_text_field((string) ($_POST[$field . '_time'] ?? '')); $value = $date !== '' ? trim($date . ' ' . ($time !== '' ? $time : '00:00')) : sanitize_text_field((string) ($_POST[$field] ?? '')); if ($value === '') { return null; } $timestamp = strtotime($value, current_time('timestamp')); return $timestamp ? gmdate('Y-m-d H:i:s', $timestamp) : null; }
    private function localInput(?string $date): string { return $date ? mysql2date('Y-m-d\TH:i', $date) : ''; }
    private function dateTimeParts(string $label, string $name, ?string $date): string { $local = $this->localInput($date); $dateValue = $local ? substr($local, 0, 10) : ''; $timeValue = $local ? substr($local, 11, 5) : ''; return '<fieldset class="qrb-date-time-parts"><legend>' . esc_html($label) . '</legend><label>Date<input type="date" name="' . esc_attr($name . '_date') . '" value="' . esc_attr($dateValue) . '"></label><label>Time<select name="' . esc_attr($name . '_time') . '">' . $this->timeOptions($timeValue) . '</select></label></fieldset>'; }
    private function timeRangeParts(string $start, string $end): string { return '<fieldset class="qrb-date-time-parts qrb-time-range qrb-form-wide"><legend>Time window</legend><label>Start time<select name="time_start">' . $this->timeOptions($start) . '</select></label><label>End time<select name="time_end">' . $this->timeOptions($end) . '</select></label></fieldset>'; }
    private function timeOptions(string $selected): string { $html = '<option value="">Select time</option>'; $values = []; for ($hour = 0; $hour < 24; $hour++) { foreach ([0, 15, 30, 45] as $minute) { $values[] = sprintf('%02d:%02d', $hour, $minute); } } if ($selected !== '' && !in_array($selected, $values, true)) { array_unshift($values, $selected); } foreach ($values as $value) { $html .= '<option value="' . esc_attr($value) . '" ' . selected($selected, $value, false) . '>' . esc_html($value) . '</option>'; } return $html; }
    private function resolutionReasonLabel(string $reason): string { $labels = ['primary' => 'Primary destination', 'scheduled' => 'Scheduled destination', 'smart_rule' => 'Smart Destination rule', 'fallback' => 'Fallback URL', 'paused' => 'Paused', 'expired' => 'Expired']; return $labels[$reason] ?? ucwords(str_replace('_', ' ', $reason)); }
    private function ruleConditionLabel(DestinationRule $rule): string { $parts = []; if ($rule->startsAt || $rule->endsAt) { $parts[] = DateTimeHelper::utcToDisplay($rule->startsAt) . ' to ' . DateTimeHelper::utcToDisplay($rule->endsAt); } if ($rule->daysOfWeek) { $parts[] = 'Days: ' . $rule->daysOfWeek; } if ($rule->timeStart || $rule->timeEnd) { $parts[] = 'Time: ' . ($rule->timeStart ?: '00:00') . ' to ' . ($rule->timeEnd ?: '23:59'); } return $parts ? implode('; ', $parts) : 'Always active'; }
    private function barChart(array $series): string { $max = max(1, ...array_map(static fn($r): int => (int) $r['scans'], $series)); $html = '<div class="qrb-bars">'; foreach ($series as $row) { $height = max(4, (int) round(((int) $row['scans'] / $max) * 90)); $html .= '<span title="' . esc_attr($row['date'] . ': ' . $row['scans']) . '" style="height:' . esc_attr((string) $height) . 'px"></span>'; } return $html . '</div>'; }
    private function breakdownTable(array $rows): string { if (!$rows) { return '<p>No data yet.</p>'; } $html = '<table class="qrb-table"><tbody>'; foreach ($rows as $row) { $html .= '<tr><td>' . esc_html((string) $row['label']) . '</td><td>' . esc_html((string) $row['count']) . '</td></tr>'; } return $html . '</tbody></table>'; }
    private function recentScansTable(array $rows): string { if (!$rows) { return '<p>No scans yet.</p>'; } $html = '<table class="qrb-table"><thead><tr><th>When</th><th>Referrer</th><th>User agent</th></tr></thead><tbody>'; foreach ($rows as $row) { $html .= '<tr><td>' . esc_html((string) $row->scanned_at) . '</td><td>' . esc_html($row->referrer ?: 'Direct / unknown') . '</td><td>' . esc_html((string) ($row->user_agent_summary ?? 'Unknown')) . '</td></tr>'; } return $html . '</tbody></table>'; }
    private function topQrTable(array $rows): string { if (!$rows) { return '<p>No scan data yet.</p>'; } $html = '<table class="qrb-table"><thead><tr><th>QR</th><th>Scans</th><th>Last scan</th></tr></thead><tbody>'; foreach ($rows as $row) { $html .= '<tr><td><a href="' . esc_url(home_url('/app/qr/' . $row->id) . '#analytics') . '">' . esc_html($row->name) . '</a></td><td>' . esc_html((string) $row->scan_count) . '</td><td>' . esc_html($row->last_scan ?: '-') . '</td></tr>'; } return $html . '</tbody></table>'; }

    private function enqueueAppAssets(): void {}
    private function headAssets(): string { remove_action('wp_print_styles', 'print_emoji_styles'); ob_start(); wp_print_styles(); wp_print_head_scripts(); return (string) ob_get_clean(); }
    private function footerAssets(): string { ob_start(); wp_print_footer_scripts(); return (string) ob_get_clean(); }
    private function appScripts(): string {
        $config = [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'previewNonce' => wp_create_nonce('qrbuzz_preview'),
            'assetsNonce' => wp_create_nonce('qrbuzz_assets'),
            'maxAssetBytes' => AssetStorage::MAX_BYTES,
        ];
        return '<script>window.QRBuzzApp=' . wp_json_encode($config) . ';</script><script>' . $this->asset('assets/js/app-shell.js') . "\n" . $this->asset('assets/js/pages/qr-studio.js') . '</script>';
    }

    private function page(string $title, string $content): void { status_header(200); nocache_headers(); echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title>' . $this->styles() . '</head><body class="qrb-body"><main class="qrb-public">' . $this->publicLogo() . $content . '</main></body></html>'; exit; }
    private function app(string $title, string $content): void {
        status_header(200); nocache_headers(); $this->enqueueAppAssets(); $w = $this->workspaces->current(); $user = wp_get_current_user();
        echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>' . esc_html($title) . '</title>' . $this->styles() . $this->headAssets() . '</head><body class="qrb-body"><a class="qrb-skip-link" href="#qrb-main">Skip to content</a><div class="qrb-mobile-backdrop" data-qrb-drawer-close></div><div class="qrb-shell"><header class="qrb-topbar"><button type="button" class="qrb-button qrb-mobile-menu" data-qrb-drawer-toggle aria-controls="qrb-sidebar" aria-expanded="false">' . $this->icon('menu') . '<span>Menu</span></button><div class="qrb-workspace"><span>Workspace</span><strong>' . esc_html($w->name) . '</strong></div><div class="qrb-topbar-actions"><a class="qrb-button qrb-account-button qrb-icon-button" href="/app/settings/account" title="' . esc_attr($user->display_name ?: 'Account') . '" aria-label="Account settings">' . $this->icon('user') . '</a></div></header><aside class="qrb-sidebar" aria-label="Workspace navigation"><a class="qrb-brand qrb-brand-logo" href="/app/dashboard"><img src="' . esc_url(QR_BUZZ_URL . 'assets/qr-buzz-logo-header.png') . '" alt="QR Buzz"></a>' . $this->navigation() . '</aside><div class="qrb-app-frame"><main id="qrb-main" class="qrb-main">' . $this->flash() . $content . '</main></div></div>' . $this->appScripts() . $this->footerAssets() . '</body></html>'; exit;
    }
    private function styles(): string { return '<style>' . $this->asset('assets/css/tokens.css') . $this->asset('assets/css/reset.css') . $this->asset('assets/css/base.css') . $this->asset('assets/css/typography.css') . $this->asset('assets/css/layout.css') . $this->asset('assets/css/components.css') . $this->asset('assets/css/forms.css') . $this->asset('assets/css/tables.css') . $this->asset('assets/css/utilities.css') . $this->asset('assets/css/pages/dashboard.css') . $this->asset('assets/css/pages/library.css') . $this->asset('assets/css/pages/assets.css') . $this->asset('assets/css/pages/qr-studio.css') . $this->asset('assets/css/pages/campaigns.css') . $this->asset('assets/css/pages/analytics.css') . $this->asset('assets/css/pages/brand-kit.css') . $this->asset('assets/css/pages/onboarding.css') . $this->asset('assets/css/pages/settings.css') . $this->asset('assets/css/responsive.css') . '</style>'; }
    private function navigation(): string {
        $path = $this->path();
        $items = [
            ['Dashboard', '/app/dashboard', 'home', 'app/dashboard'],
            ['Library', '/app/library', 'library', 'app/library'],
            ['New QR', '/app/qr/new', 'plus-square', 'app/qr/new'],
            ['Assets', '/app/assets', 'image', 'app/assets'],
            ['Campaigns', '/app/campaigns', 'megaphone', 'app/campaigns'],
            ['Analytics', '/app/analytics', 'chart', 'app/analytics'],
            ['Brand Kit', '/app/settings/workspace', 'palette', 'app/settings/workspace'],
            ['Settings', '/app/settings/account', 'settings', 'app/settings'],
            ['Log Out', '/logout', 'log-out', 'logout'],
        ];
        $html = '<nav id="qrb-sidebar" class="qrb-sidebar-nav">';
        foreach ($items as [$label, $url, $icon, $match]) {
            $active = $path === $match || str_starts_with($path, $match . '/') || ($match === 'app/settings' && str_starts_with($path, 'app/settings'));
            $html .= '<a class="qrb-nav-link ' . ($active ? 'is-active' : '') . '" href="' . esc_url(home_url($url)) . '" ' . ($active ? 'aria-current="page"' : '') . '>' . $this->icon($icon) . '<span>' . esc_html($label) . '</span></a>';
        }
        return $html . '</nav>';
    }
    private function flash(): string {
        if (!isset($_GET['saved']) && !isset($_GET['created']) && !isset($_GET['welcome']) && !isset($_GET['deleted'])) { return ''; }
        $message = isset($_GET['deleted']) ? 'QR code deleted.' : (isset($_GET['created']) ? 'Created successfully.' : (isset($_GET['welcome']) ? 'Welcome to your QR Buzz workspace.' : 'Changes saved.'));
        return '<div class="qrb-toast" data-qrb-toast role="status" aria-live="polite"><span>' . esc_html($message) . '</span><button type="button" class="qrb-toast-close" data-qrb-toast-close aria-label="Dismiss notification">Dismiss</button></div>';
    }
    private function asset(string $path): string { $file = QR_BUZZ_PATH . str_replace('/', DIRECTORY_SEPARATOR, $path); return is_readable($file) ? (string) file_get_contents($file) : ''; }
    private function icon(string $name): string {
        $icons = [
            'home' => '<path d="M3 10.5 12 3l9 7.5"/><path d="M5 10v10h14V10"/><path d="M9 20v-6h6v6"/>',
            'library' => '<path d="M4 5h7v14H4z"/><path d="M13 5h7v14h-7z"/><path d="M7 8h1"/><path d="M16 8h1"/>',
            'plus' => '<path d="M12 5v14"/><path d="M5 12h14"/>',
            'plus-square' => '<rect x="4" y="4" width="16" height="16" rx="3"/><path d="M12 8v8"/><path d="M8 12h8"/>',
            'edit' => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4 12.5-12.5z"/>',
            'download' => '<path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/>',
            'eye' => '<path d="M2 12s3.5-6 10-6 10 6 10 6-3.5 6-10 6-10-6-10-6z"/><circle cx="12" cy="12" r="3"/>',
            'image' => '<rect x="4" y="5" width="16" height="14" rx="2"/><circle cx="9" cy="10" r="1.5"/><path d="m8 17 3.5-4 2.5 3 1.5-2 3 3"/>',
            'route' => '<circle cx="6" cy="6" r="2"/><circle cx="18" cy="18" r="2"/><path d="M8 6h3a3 3 0 0 1 0 6h2a3 3 0 0 1 3 3v1"/>',
            'megaphone' => '<path d="M4 13v-2l12-5v12L4 13z"/><path d="M4 13l2 6h3l-2-5"/><path d="M18 10a3 3 0 0 1 0 4"/>',
            'chart' => '<path d="M4 19h16"/><path d="M7 16V9"/><path d="M12 16V5"/><path d="M17 16v-6"/>',
            'palette' => '<path d="M12 4a8 8 0 0 0 0 16h1.5a2 2 0 0 0 1.7-3.1 1.5 1.5 0 0 1 1.3-2.4H18a6 6 0 0 0-6-10.5z"/><circle cx="8" cy="11" r="1"/><circle cx="10" cy="8" r="1"/><circle cx="14" cy="8" r="1"/>',
            'settings' => '<path d="M12 8a4 4 0 1 0 0 8 4 4 0 0 0 0-8z"/><path d="M4 12h2"/><path d="M18 12h2"/><path d="M12 4v2"/><path d="M12 18v2"/><path d="m6.3 6.3 1.4 1.4"/><path d="m16.3 16.3 1.4 1.4"/><path d="m17.7 6.3-1.4 1.4"/><path d="m7.7 16.3-1.4 1.4"/>',
            'menu' => '<path d="M4 7h16"/><path d="M4 12h16"/><path d="M4 17h16"/>',
            'user' => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
            'chevron-down' => '<path d="m6 9 6 6 6-6"/>',
            'trash' => '<path d="M4 7h16"/><path d="M9 7V4h6v3"/><path d="m6 7 1 13h10l1-13"/><path d="M10 11v5"/><path d="M14 11v5"/>',
            'log-out' => '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/>',
        ];
        return '<svg class="qrb-icon" aria-hidden="true" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round">' . ($icons[$name] ?? $icons['home']) . '</svg>';
    }
    private function publicLogo(): string { return '<a class="qrb-public-logo" href="' . esc_url(home_url('/')) . '" aria-label="QR Buzz home"><img src="' . esc_url(QR_BUZZ_URL . 'assets/qr-buzz-logo-header.png') . '" alt="QR Buzz"></a>'; }
    private function firstName(\WP_User $user): string { return sanitize_text_field((string) ($user->first_name ?: $user->display_name ?: 'there')); }
    private function brandedEmail(string $title, string $name, string $body, string $button, string $url): string { return '<!doctype html><html><body style="margin:0;background:#c5d6d2;padding:28px;font-family:Arial,Helvetica,sans-serif;color:#1f2933;"><table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center"><table role="presentation" width="100%" style="max-width:620px;background:#ffffff;border-radius:8px;padding:30px;" cellspacing="0" cellpadding="0"><tr><td><p style="text-align:center;margin:0 0 26px;"><img src="' . esc_url(QR_BUZZ_URL . 'assets/qr-buzz-logo-header.png') . '" alt="QR Buzz" style="max-width:260px;height:auto;border:0;background:transparent;"></p><h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#20252b;">' . esc_html($title) . '</h1><p style="font-size:16px;line-height:1.6;margin:0 0 18px;">Hi ' . esc_html($name) . ',</p><p style="font-size:16px;line-height:1.6;margin:0 0 24px;">' . wp_kses_post($body) . '</p><p style="margin:0 0 26px;"><a href="' . esc_url($url) . '" style="display:inline-block;background:#219b8b;color:#ffffff;text-decoration:none;font-weight:700;border-radius:6px;padding:13px 18px;">' . esc_html($button) . '</a></p><p style="font-size:16px;line-height:1.6;margin:0;">Happy creating!<br>The QR Buzz Team</p></td></tr></table></td></tr></table></body></html>'; }
    private function path(): string { return trim(parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/'); }
    private function redirect(string $path): void { wp_safe_redirect(str_starts_with($path, 'http') ? $path : home_url($path)); exit; }
    private function rateLimited(string $scope): bool { $key = 'qrbuzz_' . $scope . '_' . md5((string) ($_SERVER['REMOTE_ADDR'] ?? '')); $count = (int) get_transient($key); set_transient($key, $count + 1, 10 * MINUTE_IN_SECONDS); return $count > 12; }
    private function notFound(): void { status_header(404); $this->app('Not found', '<h1>Page not found</h1>'); }
}
