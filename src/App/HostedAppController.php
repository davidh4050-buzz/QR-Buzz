<?php
namespace QRBuzz\App;

use QRBuzz\Account\AuthService;
use QRBuzz\Account\AuthIdentityRepository;
use QRBuzz\Account\GoogleAuthService;
use QRBuzz\Account\ProfileRepository;
use QRBuzz\Analytics\AnalyticsRepository;
use QRBuzz\Analytics\DateRange;
use QRBuzz\Analytics\PerQRAnalyticsService;
use QRBuzz\Analytics\QRInsightService;
use QRBuzz\Assets\AssetRepository;
use QRBuzz\Assets\AssetStorage;
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

    public function __construct() {
        $this->profiles = new ProfileRepository();
        $this->workspaceRepo = new WorkspaceRepository();
        $this->workspaces = new WorkspaceService($this->workspaceRepo);
        $this->plans = new PlanRegistry();
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
        $this->stripe = new StripeService($this->subscriptions, $this->workspaceRepo);
        $this->analyticsRepo = new AnalyticsRepository(null, $this->workspaces);
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
    }

    private function postRegister(): void {
        check_admin_referer('qrbuzz_register', 'nonce');
        if ($this->rateLimited('register')) { $this->redirect('/register?error=rate'); }
        $_POST['password_confirm'] = (string) ($_POST['password'] ?? '');
        $user = $this->auth->register($_POST);
        if (is_wp_error($user)) { $this->redirect('/register?error=' . rawurlencode($user->get_error_code())); }
        $this->redirect('/app/qr/new?type=dynamic_url&studio=1&welcome=1');
    }

    private function postLogin(): void {
        check_admin_referer('qrbuzz_login', 'nonce');
        if ($this->rateLimited('login')) { $this->redirect('/login?error=rate'); }
        $user = $this->auth->login((string) ($_POST['login'] ?? ''), (string) ($_POST['password'] ?? ''), !empty($_POST['remember']));
        if (is_wp_error($user)) { $this->redirect('/login?error=failed'); }
        $this->linkPendingGoogle((int) $user->ID);
        $this->redirectAfterLogin();
    }

    private function postPassword(): void {
        $mode = sanitize_key((string) ($_POST['mode'] ?? 'request'));
        if ($mode === 'reset') {
            check_admin_referer('qrbuzz_password_reset', 'nonce');
            $user = check_password_reset_key((string) ($_POST['key'] ?? ''), (string) ($_POST['login'] ?? ''));
            if (is_wp_error($user) || empty($_POST['password']) || $_POST['password'] !== ($_POST['password_confirm'] ?? '') || !$this->auth->strongPassword((string) $_POST['password'])) { $this->redirect('/forgot-password?error=reset'); }
            reset_password($user, (string) $_POST['password']);
            $this->redirect('/login?reset=1');
        }
        check_admin_referer('qrbuzz_password_request', 'nonce');
        if ($this->rateLimited('password')) { $this->redirect('/forgot-password?sent=1'); }
        $login = sanitize_text_field((string) ($_POST['login'] ?? ''));
        $user = get_user_by('email', $login) ?: get_user_by('login', $login);
        if ($user) { $key = get_password_reset_key($user); if (!is_wp_error($key)) { wp_mail($user->user_email, 'Reset your QR Buzz password', 'Reset your password: ' . add_query_arg(['action' => 'reset', 'key' => $key, 'login' => rawurlencode($user->user_login)], home_url('/forgot-password'))); } }
        $this->redirect('/forgot-password?sent=1');
    }

    private function postOnboarding(): void {
        check_admin_referer('qrbuzz_onboarding', 'nonce');
        $workspace = $this->workspaces->current();
        $action = sanitize_key((string) ($_POST['onboarding_action'] ?? ''));
        if ($action === 'complete') { $this->completeOnboarding(); (new \QRBuzz\Platform\PlatformEventRepository())->record('onboarding_completed', ['user_id' => get_current_user_id(), 'workspace_id' => $workspace->id]); $this->redirect('/app/dashboard?welcome=1'); }
        $this->redirect('/app/qr/new?type=dynamic_url&studio=1');
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
        if (!$this->entitlements->allows('advanced_branding')) { $design = QRDesignSettings::defaults(); }
        if (!$this->entitlements->allows('logo_embedding')) { $design->logoAttachmentId = 0; $design->logoAssetId = 0; }
        if (!empty($_POST['set_as_brand_kit'])) { $this->brandKit->saveFromDesign($design); }
        $settings = ['type' => $type, 'payload_data' => $payload, 'static_payload' => $staticPayload, 'status' => $status, 'fallback_url' => $this->optionalUrl('fallback_url'), 'expires_at' => $this->optionalDateTime('expires_at'), 'scheduled_url' => $this->optionalUrl('scheduled_url'), 'scheduled_start_at' => $this->optionalDateTime('scheduled_start_at'), 'scheduled_end_at' => $this->optionalDateTime('scheduled_end_at'), 'campaign_id' => absint($_POST['campaign_id'] ?? 0), 'design' => $design->toArray()];
        if ($id > 0) { $this->qrCodes->update($id, $name, $destination, $status, $settings, get_current_user_id()); $this->redirect('/app/qr/' . $id . '?saved=1'); }
        $wasOnboarding = !$this->workspaces->current()->onboardingComplete();
        $newId = $this->qrCodes->create($name, $destination, $settings);
        if ($wasOnboarding) { (new \QRBuzz\Platform\PlatformEventRepository())->record('first_qr_created', ['user_id' => get_current_user_id(), 'workspace_id' => $this->workspaces->id(), 'qr_id' => $newId]); }
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
        if (!$this->entitlements->canCreate('campaigns') && $id === 0) { $this->redirect('/apÛžõÒÚ$z{-®éÜj×Ç6R’âså&VÖ÷fRÆövóÂö'WGFöããÂ÷7ããÇ7â6Æ73Ò'&"ÖÆövò×&Wf–Wr#ârâwö·6W5÷÷7B‚F–ÖvR’âsÂ÷7ããÇ7â6Æ73Ò'&"Ö†VÇ#åärÂ¥ræBvV%Æövò'Gv÷&²—27F÷&VB–â–÷W""'W§¢76WBÆ–'&'’âæöâ×7V&RÆöv÷2&Rf—GFVBv—F†÷WB7G&WF6†–ærãÂ÷7ããÂöÆ&VÃârâGF†—2Óæ76WE–6¶W$ÖöFÂ‚“²ÐÐ¢&—fFRgVæ7F–öâæWtFW6–väFVfVÇG2‚“¢$FW6–vå6WGF–æw2²FFW6–vâÒGF†—2Óæ'&æD¶—BÓæFW6–väFVfVÇG2‚“²FFW6–vâÓæÆövôGF6†ÖVçD–BÒ²FFW6–vâÓæÆövô76WD–BÒ²&WGW&âFFW6–vã²ÐÐ¢&—fFRgVæ7F–öâ÷F–öæÅW&Â‡7G&–ærFf–VÆB“¢÷7G&–ær²GW&ÂÒW65÷W&Å÷&r‚‡7G&–ær’‚Eõõ5E²Ff–VÆEÒóòrr’“²&WGW&âG&–Ò‚GW&Â’ÓÓÒrròçVÆÂ¢GW&Ã²ÐÐ¢&—fFRgVæ7F–öâ÷F–öæÄFFUF–ÖR‡7G&–ærFf–VÆB“¢÷7G&–ær²FFFRÒ6æ—F—¦U÷FW‡Eöf–VÆB‚‡7G&–ær’‚Eõõ5E²Ff–VÆBâuöFFRuÒóòrr’“²GF–ÖRÒ6æ—F—¦U÷FW‡Eöf–VÆB‚‡7G&–ær’‚Eõõ5E²Ff–VÆBâu÷F–ÖRuÒóòrr’“²GfÇVRÒFFFRÓÒrròG&–Ò‚FFFRârrâ‚GF–ÖRÓÒrròGF–ÖR¢s£r’’¢6æ—F—¦U÷FW‡Eöf–VÆB‚‡7G&–ær’‚Eõõ5E²Ff–VÆEÒóòrr’“²–b‚GfÇVRÓÓÒrr’²&WGW&âçVÆÃ²ÒGF–ÖW7F×Ò7G'F÷F–ÖR‚GfÇVRÂ7W'&VçE÷F–ÖR‚wF–ÖW7F×r’“²&WGW&âGF–ÖW7F×òvÖFFR‚u’ÖÒÖBƒ¦“§2rÂGF–ÖW7F×’¢çVÆÃ²ÐÐ¢&—fFRgVæ7F–öâÆö6Ä–çWBƒ÷7G&–ærFFFR“¢7G&–ær²&WGW&âFFFRò×—7Ã&FFR‚u’ÖÒÖEÅDƒ¦’rÂFFFR’¢rs²ÐÐ¢&—fFRgVæ7F–öâFFUF–ÖU'G2‡7G&–ærFÆ&VÂÂ7G&–ærFæÖRÂ÷7G&–ærFFFR“¢7G&–ær²FÆö6ÂÒGF†—2ÓæÆö6Ä–çWB‚FFFR“²FFFUfÇVRÒFÆö6Âò7V'7G"‚FÆö6ÂÂÂ’¢rs²GF–ÖUfÇVRÒFÆö6Âò7V'7G"‚FÆö6ÂÂÂR’¢rs²&WGW&âsÆf–VÆG6WB6Æ73Ò'&"ÖFFR×F–ÖR×'G2#ãÆÆVvVæCârâW65ö‡FÖÂ‚FÆ&VÂ’âsÂöÆVvVæCãÆÆ&VÃäFFSÆ–çWBG—SÒ&FFR"æÖSÒ"râW65öGG"‚FæÖRâuöFFRr’âr"fÇVSÒ"râW65öGG"‚FFFUfÇVR’âr#ãÂöÆ&VÃãÆÆ&VÃåF–ÖSÇ6VÆV7BæÖSÒ"râW65öGG"‚FæÖRâu÷F–ÖRr’âr#ârâGF†—2ÓçF–ÖT÷F–öç2‚GF–ÖUfÇVR’âsÂ÷6VÆV7CãÂöÆ&VÃãÂöf–VÆG6WCâs²ÐÐ¢&—fFRgVæ7F–öâF–ÖU&ævU'G2‡7G&–ærG7F'BÂ7G&–ærFVæB“¢7G&–ær²&WGW&âsÆf–VÆG6WB6Æ73Ò'&"ÖFFR×F–ÖR×'G2&"×F–ÖR×&ævR&"Öf÷&Ò×v–FR#ãÆÆVvVæCåF–ÖRv–æF÷sÂöÆVvVæCãÆÆ&VÃå7F'BF–ÖSÇ6VÆV7BæÖSÒ'F–ÖU÷7F'B#ârâGF†—2ÓçF–ÖT÷F–öç2‚G7F'B’âsÂ÷6VÆV7CãÂöÆ&VÃãÆÆ&VÃäVæBF–ÖSÇ6VÆV7BæÖSÒ'F–ÖUöVæB#ârâGF†—2ÓçF–ÖT÷F–öç2‚FVæB’âsÂ÷6VÆV7CãÂöÆ&VÃãÂöf–VÆG6WCâs²ÐÐ¢&—fFRgVæ7F–öâF–ÖT÷F–öç2‡7G&–ærG6VÆV7FVB“¢7G&–ær²F‡FÖÂÒsÆ÷F–öâfÇVSÒ"#å6VÆV7BF–ÖSÂö÷F–öãâs²GfÇVW2ÒµÓ²f÷"‚F†÷W"Ò²F†÷W"Â#C²F†÷W"²²’²f÷&V6‚…³ÂRÂ3ÂCUÒ2FÖ–çWFR’²GfÇVW5µÒÒ7&–çFb‚rS&C¢S&BrÂF†÷W"ÂFÖ–çWFR“²ÒÒ–b‚G6VÆV7FVBÓÒrrbb–åö'&’‚G6VÆV7FVBÂGfÇVW2ÂG'VR’’²'&•÷Vç6†–gB‚GfÇVW2ÂG6VÆV7FVB“²Òf÷&V6‚‚GfÇVW22GfÇVR’²F‡FÖÂãÒsÆ÷F–öâfÇVSÒ"râW65öGG"‚GfÇVR’âr"râ6VÆV7FVB‚G6VÆV7FVBÂGfÇVRÂfÇ6R’âsârâW65ö‡FÖÂ‚GfÇVR’âsÂö÷F–öãâs²Ò&WGW&âF‡FÖÃ²ÐÐ¢&—fFRgVæ7F–öâ&W6öÇWF–öå&V6öäÆ&VÂ‡7G&–ærG&V6öâ“¢7G&–ær²FÆ&VÇ2Ò²w&–Ö'’rÓâu&–Ö'’FW7F–æF–öârÂw66†VGVÆVBrÓâu66†VGVÆVBFW7F–æF–öârÂw6Ö'E÷'VÆRrÓâu6Ö'BFW7F–æF–öâ'VÆRrÂvfÆÆ&6²rÓâtfÆÆ&6²U$ÂrÂwW6VBrÓâuW6VBrÂvW‡—&VBrÓâtW‡—&VBuÓ²&WGW&âFÆ&VÇ5²G&V6öåÒóòV7v÷&G2‡7G%÷&WÆ6R‚uòrÂrrÂG&V6öâ’“²ÐÐ¢&—fFRgVæ7F–öâ'VÆT6öæF—F–öäÆ&VÂ„FW7F–æF–öå'VÆRG'VÆR“¢7G&–ær²G'G2ÒµÓ²–b‚G'VÆRÓç7F'G4BÇÂG'VÆRÓæVæG4B’²G'G5µÒÒFFUF–ÖT†VÇW#£§WF5FôF—7Æ’‚G'VÆRÓç7F'G4B’ârFòrâFFUF–ÖT†VÇW#£§WF5FôF—7Æ’‚G'VÆRÓæVæG4B“²Ò–b‚G'VÆRÓæF—4öevVV²’²G'G5µÒÒtF—3¢râG'VÆRÓæF—4öevVV³²Ò–b‚G'VÆRÓçF–ÖU7F'BÇÂG'VÆRÓçF–ÖTVæB’²G'G5µÒÒuF–ÖS¢râ‚G'VÆRÓçF–ÖU7F'Bó¢s£r’ârFòrâ‚G'VÆRÓçF–ÖTVæBó¢s#3£S’r“²Ò&WGW&âG'G2ò–×ÆöFR‚s²rÂG'G2’¢tÇv—27F—fRs²ÐÐ¢&—fFRgVæ7F–öâ&$6†'B†'&’G6W&–W2“¢7G&–ær²FÖ‚ÒÖ‚ƒÂââæ'&•öÖ‡7FF–2fâ‚G"“¢–çBÓâ†–çB’G%²w66ç2uÒÂG6W&–W2’“²F‡FÖÂÒsÆF—b6Æ73Ò'&"Ö&'2#âs²f÷&V6‚‚G6W&–W22G&÷r’²F†V–v‡BÒÖ‚ƒBÂ†–çB’&÷VæB‚‚†–çB’G&÷u²w66ç2uÒòFÖ‚’¢“’“²F‡FÖÂãÒsÇ7âF—FÆSÒ"râW65öGG"‚G&÷u²vFFRuÒâs¢râG&÷u²w66ç2uÒ’âr"7G–ÆSÒ&†V–v‡C¢râW65öGG"‚‡7G&–ær’F†V–v‡B’âw‚#ãÂ÷7ãâs²Ò&WGW&âF‡FÖÂâsÂöF—câs²ÐÐ¢&—fFRgVæ7F–öâ'&V¶F÷våF&ÆR†'&’G&÷w2“¢7G&–ær²–b‚G&÷w2’²&WGW&âsÇäæòFF–WBãÂ÷âs²ÒF‡FÖÂÒsÇF&ÆR6Æ73Ò'&"×F&ÆR#ãÇF&öG“âs²f÷&V6‚‚G&÷w22G&÷r’²F‡FÖÂãÒsÇG#ãÇFCârâW65ö‡FÖÂ‚‡7G&–ær’G&÷u²vÆ&VÂuÒ’âsÂ÷FCãÇFCârâW65ö‡FÖÂ‚‡7G&–ær’G&÷u²v6÷VçBuÒ’âsÂ÷FCãÂ÷G#âs²Ò&WGW&âF‡FÖÂâsÂ÷F&öG“ãÂ÷F&ÆSâs²ÐÐ¢&—fFRgVæ7F–öâ&V6VçE66ç5F&ÆR†'&’G&÷w2“¢7G&–ær²–b‚G&÷w2’²&WGW&âsÇäæò66ç2–WBãÂ÷âs²ÒF‡FÖÂÒsÇF&ÆR6Æ73Ò'&"×F&ÆR#ãÇF†VCãÇG#ãÇFƒåv†VãÂ÷FƒãÇFƒå&VfW'&W#Â÷FƒãÇFƒåW6W"vVçCÂ÷FƒãÂ÷G#ãÂ÷F†VCãÇF&öG“âs²f÷&V6‚‚G&÷w22G&÷r’²F‡FÖÂãÒsÇG#ãÇFCârâW65ö‡FÖÂ‚‡7G&–ær’G&÷rÓç66ææVEöB’âsÂ÷FCãÇFCârâW65ö‡FÖÂ‚G&÷rÓç&VfW'&W"ó¢tF—&V7BòVæ¶æ÷vâr’âsÂ÷FCãÇFCârâW65ö‡FÖÂ‚‡7G&–ær’‚G&÷rÓçW6W%övVçE÷7VÖÖ'’óòuVæ¶æ÷vâr’’âsÂ÷FCãÂ÷G#âs²Ò&WGW&âF‡FÖÂâsÂ÷F&öG“ãÂ÷F&ÆSâs²ÐÐ¢&—fFRgVæ7F–öâF÷%F&ÆR†'&’G&÷w2“¢7G&–ær²–b‚G&÷w2’²&WGW&âsÇäæò66âFF–WBãÂ÷âs²ÒF‡FÖÂÒsÇF&ÆR6Æ73Ò'&"×F&ÆR#ãÇF†VCãÇG#ãÇFƒå#Â÷FƒãÇFƒå66ç3Â÷FƒãÇFƒäÆ7B66ãÂ÷FƒãÂ÷G#ãÂ÷F†VCãÇF&öG“âs²f÷&V6‚‚G&÷w22G&÷r’²F‡FÖÂãÒsÇG#ãÇFCãÆ‡&VcÒ"râW65÷W&Â††öÖU÷W&Â‚rö÷"òrâG&÷rÓæ–B’’âr#ârâW65ö‡FÖÂ‚G&÷rÓææÖR’âsÂöãÂ÷FCãÇFCârâW65ö‡FÖÂ‚‡7G&–ær’G&÷rÓç66åö6÷VçB’âsÂ÷FCãÇFCârâW65ö‡FÖÂ‚G&÷rÓæÆ7E÷66âó¢rÒr’âsÂ÷FCãÂ÷G#âs²Ò&WGW&âF‡FÖÂâsÂ÷F&öG“ãÂ÷F&ÆSâs²ÐÐ Ð¢&—fFRgVæ7F–öâVçVWVT76WG2‚“¢fö–B·ÐÐ¢&—fFRgVæ7F–öâ†VD76WG2‚“¢7G&–ær²&VÖ÷fUö7F–öâ‚ww÷&–çE÷7G–ÆW2rÂw&–çEöVÖö¦•÷7G–ÆW2r“²ö%÷7F'B‚“²w÷&–çE÷7G–ÆW2‚“²w÷&–çEö†VE÷67&—G2‚“²&WGW&â‡7G&–ær’ö%övWEö6ÆVâ‚“²ÐÐ¢&—fFRgVæ7F–öâfö÷FW$76WG2‚“¢7G&–ær²ö%÷7F'B‚“²w÷&–çEöfö÷FW%÷67&—G2‚“²&WGW&â‡7G&–ær’ö%övWEö6ÆVâ‚“²ÐÐ¢&—fFRgVæ7F–öâ67&—G2‚“¢7G&–ær°Ð¢F6öæf–rÒ°Ð¢v¦…W&ÂrÓâFÖ–å÷W&Â‚vFÖ–âÖ¦‚ç‡r’ÀÐ¢w&Wf–Wtæöæ6RrÓâwö7&VFUöæöæ6R‚w&'W§¥÷&Wf–Wrr’ÀÐ¢v76WG4æöæ6RrÓâwö7&VFUöæöæ6R‚w&'W§¥ö76WG2r’ÀÐ¢vÖ„76WD'—FW2rÓâ76WE7F÷&vS£¤Ô…ô%•DU2ÀÐ¢Ó°Ð¢&WGW&âsÇ67&—Cçv–æF÷rå$'W§¤Òrâwö§6öåöVæ6öFR‚F6öæf–r’âs³Â÷67&—CãÇ67&—CârâGF†—2Óæ76WB‚v76WG2ö§2ö×6†VÆÂæ§2r’â%Æâ"âGF†—2Óæ76WB‚v76WG2ö§2÷vW2÷"×7GVF–òæ§2r’âsÂ÷67&—Câs°Ð¢ÐÐ Ð¢&—fFRgVæ7F–öâvR‡7G&–ærGF—FÆRÂ7G&–ærF6öçFVçB“¢fö–B²7FGW5ö†VFW"ƒ#“²æö66†Uö†VFW'2‚“²V6†òsÂFö7G—R‡FÖÃãÆ‡FÖÃãÆ†VCãÆÖWF6†'6WCÒ'WFbÓ‚#ãÆÖWFæÖSÒ'f–Ww÷'B"6öçFVçCÒ'v–GFƒÖFWf–6R×v–GF‚Æ–æ—F–Â×66ÆSÓ#ãÇF—FÆSârâW65ö‡FÖÂ‚GF—FÆR’âsÂ÷F—FÆSârâGF†—2Óç7G–ÆW2‚’âsÂö†VCãÆ&öG’6Æ73Ò'&"Ö&öG’#ãÆÖ–â6Æ73Ò'&"×V&Æ–2#ãÆ6Æ73Ò'&"Ö'&æB"‡&VcÒ"ò#ãÇ7â6Æ73Ò'&"Ö'&æBÖÖ&²#å#Â÷7ããÇ7ãå"'W§£Â÷7ããÂöârâF6öçFVçBâsÂöÖ–ããÂö&öG“ãÂö‡FÖÃâs²W†—C²ÐÐ¢&—fFRgVæ7F–öâ‡7G&–ærGF—FÆRÂ7G&–ærF6öçFVçB“¢fö–B°Ð¢7FGW5ö†VFW"ƒ#“²æö66†Uö†VFW'2‚“²GF†—2ÓæVçVWVT76WG2‚“²GrÒGF†—2Óçv÷&·76W2Óæ7W'&VçB‚“²GW6W"ÒwövWEö7W'&VçE÷W6W"‚“°Ð¢V6†òsÂFö7G—R‡FÖÃãÆ‡FÖÃãÆ†VCãÆÖWF6†'6WCÒ'WFbÓ‚#ãÆÖWFæÖSÒ'f–Ww÷'B"6öçFVçCÒ'v–GFƒÖFWf–6R×v–GF‚Æ–æ—F–Â×66ÆSÓ#ãÇF—FÆSârâW65ö‡FÖÂ‚GF—FÆR’âsÂ÷F—FÆSârâGF†—2Óç7G–ÆW2‚’âGF†—2Óæ†VD76WG2‚’âsÂö†VCãÆ&öG’6Æ73Ò'&"Ö&öG’#ãÆ6Æ73Ò'&"×6¶—ÖÆ–æ²"‡&VcÒ"7&"ÖÖ–â#å6¶—Fò6öçFVçCÂöãÆF—b6Æ73Ò'&"ÖÖö&–ÆRÖ&6¶G&÷"FF×&"ÖG&vW"Ö6Æ÷6SãÂöF—cãÆF—b6Æ73Ò'&"×6†VÆÂ#ãÆ†VFW"6Æ73Ò'&"×F÷&"#ãÆ'WGFöâG—SÒ&'WGFöâ"6Æ73Ò'&"Ö'WGFöâ&"ÖÖö&–ÆRÖÖVçR"FF×&"ÖG&vW"×FövvÆR&–Ö6öçG&öÇ3Ò'&"×6–FV&""&–ÖW‡æFVCÒ&fÇ6R#ârâGF†—2Óæ–6öâ‚vÖVçRr’âsÇ7ãäÖVçSÂ÷7ããÂö'WGFöããÆF—b6Æ73Ò'&"×v÷&·76R#ãÇ7ãåv÷&·76SÂ÷7ããÇ7G&öæsârâW65ö‡FÖÂ‚GrÓææÖR’âsÂ÷7G&öæsãÂöF—cãÆF—b6Æ73Ò'&"×F÷&"Ö7F–öç2#ãÆ6Æ73Ò'&"Ö'WGFöâ&"Ö66÷VçBÖ'WGFöâ&"Ö–6öâÖ'WGFöâ"‡&VcÒ"ö÷6WGF–æw2ö66÷VçB"F—FÆSÒ"râW65öGG"‚GW6W"ÓæF—7Æ•öæÖRó¢t66÷VçBr’âr"&–ÖÆ&VÃÒ$66÷VçB6WGF–æw2#ârâGF†—2Óæ–6öâ‚wW6W"r’âsÂöãÂöF—cãÂö†VFW#ãÆ6–FR6Æ73Ò'&"×6–FV&""&–ÖÆ&VÃÒ%v÷&·76Ræf–vF–öâ#ãÆ6Æ73Ò'&"Ö'&æB&"Ö'&æBÖÆövò"‡&VcÒ"ööF6†&ö&B#ãÆ–Ör7&3Ò"râW65÷W&Â…%ô%U¥¥õU$Ââv76WG2÷"Ö'W§¢ÖÆövòÖ†VFW"çærr’âr"ÇCÒ%"'W§¢#ãÂöârâGF†—2Óææf–vF–öâ‚’âsÂö6–FSãÆF—b6Æ73Ò'&"ÖÖg&ÖR#ãÆÖ–â–CÒ'&"ÖÖ–â"6Æ73Ò'&"ÖÖ–â#ârâGF†—2ÓæfÆ6‚‚’âGF†—2ÓçfW&–f–6F–öäæ÷F–6R‚’âF6öçFVçBâsÂöÖ–ããÂöF—cãÂöF—cârâGF†—2Óæ67&—G2‚’âGF†—2Óæfö÷FW$76WG2‚’âsÂö&öG“ãÂö‡FÖÃâs²W†—C°¢ÐÐ¢&—fFRgVæ7F–öâ7G–ÆW2‚“¢7G&–ær²&WGW&âsÇ7G–ÆSârâGF†—2Óæ76WB‚v76WG2ö772÷Fö¶Vç2æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷&W6WBæ772r’âGF†—2Óæ76WB‚v76WG2ö772ö&6Ræ772r’âGF†—2Óæ76WB‚v76WG2ö772÷G—öw&‡’æ772r’âGF†—2Óæ76WB‚v76WG2ö772öÆ–÷WBæ772r’âGF†—2Óæ76WB‚v76WG2ö772ö6ö×öæVçG2æ772r’âGF†—2Óæ76WB‚v76WG2ö772öf÷&×2æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷F&ÆW2æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷WF–Æ—F–W2æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2öF6†&ö&Bæ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2öÆ–'&'’æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2ö76WG2æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2÷"×7GVF–òæ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2ö6×–vç2æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2öæÇ—F–72æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2ö'&æBÖ¶—Bæ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2ööæ&ö&F–æræ772r’âGF†—2Óæ76WB‚v76WG2ö772÷vW2÷6WGF–æw2æ772r’âGF†—2Óæ76WB‚v76WG2ö772÷&W7öç6—fRæ772r’âsÂ÷7G–ÆSâs²ÐÐ¢&—fFRgVæ7F–öâæf–vF–öâ‚“¢7G&–ær°Ð¢GF‚ÒGF†—2ÓçF‚‚“°Ð¢F—FV×2Ò°Ð¢²tF6†&ö&BrÂrööF6†&ö&BrÂv†öÖRrÂvöF6†&ö&BuÒÀÐ¢²tÆ–'&'’rÂrööÆ–'&'’rÂvÆ–'&'’rÂvöÆ–'&'’uÒÀÐ¢²tæWr"rÂrö÷"öæWrrÂwÇW2×7V&RrÂv÷"öæWruÒÀÐ¢²t76WG2rÂröö76WG2rÂv–ÖvRrÂvö76WG2uÒÀÐ¢²t6×–vç2rÂröö6×–vç2rÂvÖVv†öæRrÂvö6×–vç2uÒÀÐ¢²tæÇ—F–72rÂrööæÇ—F–72rÂv6†'BrÂvöæÇ—F–72uÒÀÐ¢²t'&æB¶—BrÂrö÷6WGF–æw2÷v÷&·76RrÂwÆWGFRrÂv÷6WGF–æw2÷v÷&·76RuÒÀÐ¢²u6WGF–æw2rÂrö÷6WGF–æw2ö66÷VçBrÂw6WGF–æw2rÂv÷6WGF–æw2uÒÀÐ¢Ó°Ð¢F‡FÖÂÒsÆæb–CÒ'&"×6–FV&""6Æ73Ò'&"×6–FV&"Öæb#âs°Ð¢f÷&V6‚‚F—FV×22²FÆ&VÂÂGW&ÂÂF–6öâÂFÖF6…Ò’°Ð¢F7F—fRÒGF‚ÓÓÒFÖF6‚ÇÂ7G%÷7F'G5÷v—F‚‚GF‚ÂFÖF6‚âròr’ÇÂ‚FÖF6‚ÓÓÒv÷6WGF–æw2rbb7G%÷7F'G5÷v—F‚‚GF‚Âv÷6WGF–æw2r’“°Ð¢F‡FÖÂãÒsÆ6Æ73Ò'&"ÖæbÖÆ–æ²râ‚F7F—fRòv—2Ö7F—fRr¢rr’âr"‡&VcÒ"râW65÷W&Â††öÖU÷W&Â‚GW&Â’’âr"râ‚F7F—fRòv&–Ö7W'&VçCÒ'vR"r¢rr’âsârâGF†—2Óæ–6öâ‚F–6öâ’âsÇ7ãârâW65ö‡FÖÂ‚FÆ&VÂ’âsÂ÷7ããÂöâs°Ð¢ÐÐ¢&WGW&âF‡FÖÂâsÂöæcâs°Ð¢ÐÐ¢&—fFRgVæ7F–öâfÆ6‚‚“¢7G&–ær°¢–b‚—76WB‚EôtUE²w6fVBuÒ’bb—76WB‚EôtUE²v7&VFVBuÒ’bb—76WB‚EôtUE²wvVÆ6öÖRuÒ’bb—76WB‚EôtUE²vFVÆWFVBuÒ’’²&WGW&ârs²ÐÐ¢FÖW76vRÒ—76WB‚EôtUE²vFVÆWFVBuÒ’òu"6öFRFVÆWFVBâr¢†—76WB‚EôtUE²v7&VFVBuÒ’òt7&VFVB7V66W76gVÆÇ’âr¢†—76WB‚EôtUE²wvVÆ6öÖRuÒ’òuvVÆ6öÖRFò–÷W""'W§¢v÷&·76Râr¢t6†ævW26fVBâr’“°Ð¢&WGW&âsÆF—b6Æ73Ò'&"×Fö7B"FF×&"×Fö7B&öÆSÒ'7FGW2"&–ÖÆ—fSÒ'öÆ—FR#ãÇ7ãârâW65ö‡FÖÂ‚FÖW76vR’âsÂ÷7ããÆ'WGFöâG—SÒ&'WGFöâ"6Æ73Ò'&"×Fö7BÖ6Æ÷6R"FF×&"×Fö7BÖ6Æ÷6R&–ÖÆ&VÃÒ$F—6Ö—72æ÷F–f–6F–öâ#äF—6Ö—73Âö'WGFöããÂöF—câs°Ð¢Ð¢&—fFRgVæ7F–öâfW&–f–6F–öäæ÷F–6R‚“¢7G&–ær²G&öf–ÆRÒGF†—2Óç&öf–ÆW2Óç&öf–ÆR†vWEö7W'&VçE÷W6W%ö–B‚’“²–b‚G&öf–ÆRbbV×G’‚G&öf–ÆRÓæVÖ–Å÷fW&–f–VB’’²&WGW&ârs²ÒGW6W"ÒwövWEö7W'&VçE÷W6W"‚“²&WGW&âsÆ6–FR6Æ73Ò'&"ÖÆW'B&"×fW&–f–6F–öâÖæ÷F–6R"&öÆSÒ'7FGW2#ãÆF—cãÇ7G&öæsåfW&–g’–÷W"VÖ–ÃÂ÷7G&öæsãÇåvR6VçBfW&–f–6F–öâÆ–æ²FòrâW65ö‡FÖÂ‚GW6W"ÓçW6W%öVÖ–Â’ârãÂ÷ãÂöF—cãÆ6Æ73Ò'&"Ö'WGFöâ"‡&VcÒ"râW65÷W&Â‡wöæöæ6U÷W&Â††öÖU÷W&Â‚r÷&W6VæB×fW&–f–6F–öâr’Âw&'W§¥÷&W6VæE÷fW&–f–6F–öâr’’âr#å&W6VæBVÖ–ÃÂöãÂö6–FSâs²Ð¢&—fFRgVæ7F–öâ76WB‡7G&–ærGF‚“¢7G&–ær²Ff–ÆRÒ%ô%U¥¥õD‚â7G%÷&WÆ6R‚ròrÂD•$T5Dõ%•õ4U$Dõ"ÂGF‚“²&WGW&â—5÷&VF&ÆR‚Ff–ÆR’ò‡7G&–ær’f–ÆUövWEö6öçFVçG2‚Ff–ÆR’¢rs²ÐÐ¢&—fFRgVæ7F–öâ–6öâ‡7G&–ærFæÖR“¢7G&–ær°Ð¢F–6öç2Ò°Ð¢v†öÖRrÓâsÇF‚CÒ$Ó2ãR"6Ã’rãR"óãÇF‚CÒ$ÓRcƒEc"óãÇF‚CÒ$Ó’#bÓfƒgcb"óârÀÐ¢vÆ–'&'’rÓâsÇF‚CÒ$ÓBVƒwcDƒG¢"óãÇF‚CÒ$Ó2VƒwcF‚Ów¢"óãÇF‚CÒ$Ór†ƒ"óãÇF‚CÒ$Ób†ƒ"óârÀÐ¢wÇW2rÓâsÇF‚CÒ$Ó"WcB"óãÇF‚CÒ$ÓR&ƒB"óârÀÐ¢wÇW2×7V&RrÓâsÇ&V7BƒÒ#B"“Ò#B"v–GFƒÒ#b"†V–v‡CÒ#b"'ƒÒ#2"óãÇF‚CÒ$Ó"‡c‚"óãÇF‚CÒ$Ó‚&ƒ‚"óârÀÐ¢vVF—BrÓâsÇF‚CÒ$Ó"#ƒ’"óãÇF‚CÒ$ÓbãR2ãV"ã"ã24Ãr–ÂÓBÓB"ãRÓ"ãW¢"óârÀÐ¢vF÷væÆöBrÓâsÇF‚CÒ$Ó"7c""óãÇF‚CÒ&ÓrRRRÓR"óãÇF‚CÒ$ÓR#ƒB"óârÀÐ¢vW–RrÓâsÇF‚CÒ$Ó"'32ãRÓbÓbbbÓ2ãRbÓbÓÓbÓÓg¢"óãÆ6—&6ÆR7ƒÒ#""7“Ò#""#Ò#2"óârÀÐ¢v–ÖvRrÓâsÇ&V7BƒÒ#B"“Ò#R"v–GFƒÒ#b"†V–v‡CÒ#B"'ƒÒ#""óãÆ6—&6ÆR7ƒÒ#’"7“Ò#"#Ò#ãR"óãÇF‚CÒ&Ó‚r2ãRÓB"ãR2ãRÓ"22"óârÀÐ¢w&÷WFRrÓâsÆ6—&6ÆR7ƒÒ#b"7“Ò#b"#Ò#""óãÆ6—&6ÆR7ƒÒ#‚"7“Ò#‚"#Ò#""óãÇF‚CÒ$Ó‚fƒ622fƒ&2227c"óârÀÐ¢vÖVv†öæRrÓâsÇF‚CÒ$ÓB7bÓ&Ã"ÓWc$ÃB7¢"óãÇF‚CÒ$ÓB6Ã"fƒ6ÂÓ"ÓR"óãÇF‚CÒ$Ó‚22B"óârÀÐ¢v6†'BrÓâsÇF‚CÒ$ÓB–ƒb"óãÇF‚CÒ$Órec’"óãÇF‚CÒ$Ó"ecR"óãÇF‚CÒ$ÓrgbÓb"óârÀÐ¢wÆWGFRrÓâsÇF‚CÒ$Ó"F‚‚fƒãV""ãrÓ2ããRãRã2Ó"ãDƒ†bbÓbÓãW¢"óãÆ6—&6ÆR7ƒÒ#‚"7“Ò#"#Ò#"óãÆ6—&6ÆR7ƒÒ#"7“Ò#‚"#Ò#"óãÆ6—&6ÆR7ƒÒ#B"7“Ò#‚"#Ò#"óârÀÐ¢w6WGF–æw2rÓâsÇF‚CÒ$Ó"†BB‚BBÓ‡¢"óãÇF‚CÒ$ÓB&ƒ""óãÇF‚CÒ$Ó‚&ƒ""óãÇF‚CÒ$Ó"Gc""óãÇF‚CÒ$Ó"‡c""óãÇF‚CÒ&Óbã2bã2ãBãB"óãÇF‚CÒ&Óbã2bã2ãBãB"óãÇF‚CÒ&Órãrbã2ÓãBãB"óãÇF‚CÒ&Órãrbã2ÓãBãB"óârÀÐ¢vÖVçRrÓâsÇF‚CÒ$ÓBvƒb"óãÇF‚CÒ$ÓB&ƒb"óãÇF‚CÒ$ÓBvƒb"óârÀÐ¢wW6W"rÓâsÆ6—&6ÆR7ƒÒ#""7“Ò#‚"#Ò#B"óãÇF‚CÒ$ÓB#‚‚b"óârÀÐ¢v6†Wg&öâÖF÷vârÓâsÇF‚CÒ&Ób’bbbÓb"óârÀÐ¢wG&6‚rÓâsÇF‚CÒ$ÓBvƒb"óãÇF‚CÒ$Ó’ucFƒgc2"óãÇF‚CÒ&Óbr6ƒÃÓ2"óãÇF‚CÒ$ÓcR"óãÇF‚CÒ$ÓBcR"óârÀÐ¢Ó°Ð¢&WGW&âsÇ7fr6Æ73Ò'&"Ö–6öâ"&–Ö†–FFVãÒ'G'VR"f–Wt&÷ƒÒ##B#B"f–ÆÃÒ&æöæR"7G&ö¶SÒ&7W'&VçD6öÆ÷""7G&ö¶R×v–GFƒÒ#ã‚"7G&ö¶RÖÆ–æV6Ò'&÷VæB"7G&ö¶RÖÆ–æV¦ö–ãÒ'&÷VæB#ârâ‚F–6öç5²FæÖUÒóòF–6öç5²v†öÖRuÒ’âsÂ÷7fsâs°Ð¢ÐÐ¢&—fFRgVæ7F–öâF‚‚“¢7G&–ær²&WGW&âG&–Ò‡'6U÷W&Â‚‡7G&–ær’‚Eõ4U%dU%²u$UTU5EõU$’uÒóòròr’Â…õU$ÅõD‚’Âròr“²ÐÐ¢&—fFRgVæ7F–öâ&VF—&V7B‡7G&–ærGF‚“¢fö–B²w÷6fU÷&VF—&V7B‡7G%÷7F'G5÷v—F‚‚GF‚Âv‡GGr’òGF‚¢†öÖU÷W&Â‚GF‚’“²W†—C²ÐÐ¢&—fFRgVæ7F–öâ&FTÆ–Ö—FVB‡7G&–ærG66÷R“¢&ööÂ²F¶W’Òw&'W§¥òrâG66÷RâuòrâÖCR‚‡7G&–ær’‚Eõ4U%dU%²u$TÔõDUôDE"uÒóòrr’“²F6÷VçBÒ†–çB’vWE÷G&ç6–VçB‚F¶W’“²6WE÷G&ç6–VçB‚F¶W’ÂF6÷VçB²Â¢Ô”åUDUô”åõ4T4ôäE2“²&WGW&âF6÷VçBâ#²ÐÐ¢&—fFRgVæ7F–öâæ÷Df÷VæB‚“¢fö–B²7FGW5ö†VFW"ƒCB“²GF†—2Óæ‚tæ÷Bf÷VæBrÂsÆƒåvRæ÷Bf÷VæCÂöƒâr“²ÐÐ§ÐÐ