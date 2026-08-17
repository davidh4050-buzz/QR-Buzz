<?php
namespace QRBuzz\Account;

use QRBuzz\Billing\SubscriptionRepository;
use QRBuzz\Database\WorkspaceRepository;
use QRBuzz\Platform\PlatformEventRepository;

class AuthService {

    private ProfileRepository $profiles;
    private WorkspaceRepository $workspaces;
    private SubscriptionRepository $subscriptions;
    private PlatformEventRepository $events;

    public function __construct(?ProfileRepository $profiles = null, ?WorkspaceRepository $workspaces = null, ?SubscriptionRepository $subscriptions = null, ?PlatformEventRepository $events = null) {
        $this->profiles = $profiles ?: new ProfileRepository();
        $this->workspaces = $workspaces ?: new WorkspaceRepository();
        $this->subscriptions = $subscriptions ?: new SubscriptionRepository();
        $this->events = $events ?: new PlatformEventRepository();
    }

    public function register(array $data) {
        $email = sanitize_email((string) ($data['email'] ?? ''));
        $first = sanitize_text_field((string) ($data['first_name'] ?? ''));
        $last = sanitize_text_field((string) ($data['last_name'] ?? ''));
        $password = (string) ($data['password'] ?? '');
        $confirm = (string) ($data['password_confirm'] ?? '');
        if (!$first || !$last || !is_email($email) || !$password || $password !== $confirm || empty($data['terms'])) { return new \WP_Error('registration_failed', 'Please check your details and try again.'); }
        if (email_exists($email)) { return new \WP_Error('registration_failed', 'Please check your details and try again.'); }
        if (!$this->strongPassword($password)) { return new \WP_Error('weak_password', 'Please choose a stronger password.'); }
        $userId = wp_insert_user(['user_login' => $email, 'user_email' => $email, 'user_pass' => $password, 'first_name' => $first, 'last_name' => $last, 'display_name' => trim($first . ' ' . $last), 'role' => 'qrbuzz_customer']);
        if (is_wp_error($userId)) { return new \WP_Error('registration_failed', 'Please check your details and try again.'); }
        $this->profiles->ensure((int) $userId, true);
        update_user_meta((int) $userId, 'qrbuzz_first_run_checklist', 1);
        $workspaceId = $this->workspaces->createForUser((int) $userId, $this->workspaceName($first), 'free', ['onboarding_status' => 'new', 'onboarding_step' => 'first_qr']);
        $this->subscriptions->ensureFree($workspaceId, (int) $userId);
        $this->sendVerification((int) $userId);
        $this->events->record('user_registered', ['user_id' => (int) $userId, 'workspace_id' => $workspaceId]);
        $this->events->record('workspace_created', ['user_id' => (int) $userId, 'workspace_id' => $workspaceId]);
        wp_set_current_user((int) $userId);
        wp_set_auth_cookie((int) $userId, true, is_ssl());
        return (int) $userId;
    }

    public function registerGoogle(array $identity) {
        $email = sanitize_email((string) ($identity['email'] ?? ''));
        if (empty($identity['email_verified']) || !is_email($email) || email_exists($email)) { return new \WP_Error('google_collision', 'An account already exists for this email.'); }
        $first = sanitize_text_field((string) ($identity['given_name'] ?? '')); $last = sanitize_text_field((string) ($identity['family_name'] ?? ''));
        $userId = wp_insert_user(['user_login' => $email, 'user_email' => $email, 'user_pass' => wp_generate_password(48, true, true), 'first_name' => $first, 'last_name' => $last, 'display_name' => trim($first . ' ' . $last) ?: $email, 'role' => 'qrbuzz_customer']);
        if (is_wp_error($userId)) { return new \WP_Error('registration_failed', 'We could not create your account.'); }
        $this->profiles->ensure((int) $userId, true); $this->profiles->markEmailVerified((int) $userId);
        update_user_meta((int) $userId, 'qrbuzz_first_run_checklist', 1);
        $workspaceId = $this->workspaces->createForUser((int) $userId, $this->workspaceName($first), 'free', ['onboarding_status' => 'new', 'onboarding_step' => 'first_qr']);
        $this->subscriptions->ensureFree($workspaceId, (int) $userId);
        $this->events->record('user_registered', ['user_id' => (int) $userId, 'workspace_id' => $workspaceId, 'method' => 'google']);
        $this->events->record('workspace_created', ['user_id' => (int) $userId, 'workspace_id' => $workspaceId]);
        return (int) $userId;
    }

    public function establishSession(int $userId, bool $remember = true): void { wp_set_current_user($userId); wp_set_auth_cookie($userId, $remember, is_ssl()); $this->profiles->markLogin($userId); }

    public function login(string $login, string $password, bool $remember = false) {
        $user = wp_signon(['user_login' => sanitize_text_field($login), 'user_password' => $password, 'remember' => $remember], is_ssl());
        if (is_wp_error($user)) { return new \WP_Error('login_failed', 'Please check your details and try again.'); }
        $this->profiles->markLogin((int) $user->ID);
        return $user;
    }

    public function sendVerification(int $userId): void {
        $user = get_user_by('id', $userId); if (!$user) { return; }
        $token = wp_generate_password(32, false, false);
        $this->profiles->setVerificationToken($userId, $token);
        $url = add_query_arg(['user' => $userId, 'token' => $token], home_url('/verify-email'));
        wp_mail(
            $user->user_email,
            'Welcome to QR Buzz - verify your email',
            $this->brandedEmail(
                'Welcome to QR Buzz',
                $this->firstName($user),
                'Welcome to the world of QR Buzz. We\'re thrilled you have signed up! Let\'s get you started!<br><br>First let\'s verify your email address and then you can get stuck in!',
                'Verify your email',
                $url
            ),
            ['Content-Type: text/html; charset=UTF-8']
        );
        $this->events->record('verification_sent', ['user_id' => $userId]);
    }

    public function strongPassword(string $password): bool { return strlen($password) >= 10 && preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/[0-9]/', $password); }
    private function workspaceName(string $first): string { return ($first !== '' ? $first : 'My') . ($first !== '' ? "'s Workspace" : ' Workspace'); }
    private function firstName(\WP_User $user): string { return sanitize_text_field((string) ($user->first_name ?: $user->display_name ?: 'there')); }
    private function brandedEmail(string $title, string $name, string $body, string $button, string $url): string {
        $logo = defined('QR_BUZZ_URL') ? QR_BUZZ_URL . 'assets/qr-buzz-logo-header.png' : '';
        return '<!doctype html><html><body style="margin:0;background:#c5d6d2;padding:28px;font-family:Arial,Helvetica,sans-serif;color:#1f2933;">'
            . '<table role="presentation" width="100%" cellspacing="0" cellpadding="0"><tr><td align="center">'
            . '<table role="presentation" width="100%" style="max-width:620px;background:#ffffff;border-radius:8px;padding:30px;" cellspacing="0" cellpadding="0"><tr><td>'
            . ($logo ? '<p style="text-align:center;margin:0 0 26px;"><img src="' . esc_url($logo) . '" alt="QR Buzz" style="max-width:260px;height:auto;border:0;background:transparent;"></p>' : '')
            . '<h1 style="margin:0 0 18px;font-size:26px;line-height:1.25;color:#20252b;">' . esc_html($title) . '</h1>'
            . '<p style="font-size:16px;line-height:1.6;margin:0 0 18px;">Hi ' . esc_html($name) . ',</p>'
            . '<p style="font-size:16px;line-height:1.6;margin:0 0 24px;">' . wp_kses_post($body) . '</p>'
            . '<p style="margin:0 0 26px;"><a href="' . esc_url($url) . '" style="display:inline-block;background:#219b8b;color:#ffffff;text-decoration:none;font-weight:700;border-radius:6px;padding:13px 18px;">' . esc_html($button) . '</a></p>'
            . '<p style="font-size:16px;line-height:1.6;margin:0;">We hope you enjoy using QR Buzz. Happy creating!<br>The QR Buzz Team</p>'
            . '</td></tr></table></td></tr></table></body></html>';
    }
}
