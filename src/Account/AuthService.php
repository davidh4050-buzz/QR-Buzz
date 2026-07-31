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
        $workspaceId = $this->workspaces->createForUser((int) $userId, $first . "'s Workspace", 'free', ['onboarding_status' => 'pending', 'onboarding_step' => 'plan']);
        $this->subscriptions->ensureFree($workspaceId, (int) $userId);
        $this->sendVerification((int) $userId);
        $this->events->record('user_registered', ['user_id' => (int) $userId, 'workspace_id' => $workspaceId]);
        $this->events->record('workspace_created', ['user_id' => (int) $userId, 'workspace_id' => $workspaceId]);
        wp_set_current_user((int) $userId);
        wp_set_auth_cookie((int) $userId, true, is_ssl());
        return (int) $userId;
    }

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
        wp_mail($user->user_email, 'Verify your QR Buzz account', "Welcome to QR Buzz. Verify your email here: " . $url);
    }

    public function strongPassword(string $password): bool { return strlen($password) >= 10 && preg_match('/[a-z]/', $password) && preg_match('/[A-Z]/', $password) && preg_match('/[0-9]/', $password); }
}
