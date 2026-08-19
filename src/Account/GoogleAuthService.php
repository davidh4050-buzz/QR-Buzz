<?php
namespace QRBuzz\Account;

class GoogleAuthService {
    public function configured(): bool { return $this->clientId() !== '' && $this->clientSecret() !== ''; }
    public function clientIdPresent(): bool { return $this->clientId() !== ''; }
    public function secretPresent(): bool { return $this->clientSecret() !== ''; }
    public function callbackUrl(): string { return home_url('/auth/google/callback'); }

    public function authorizationUrl(string $intent = 'login'): string {
        if (!$this->configured()) { return ''; }
        $state = wp_generate_password(48, false, false); $nonce = wp_generate_password(48, false, false);
        set_transient('qrbuzz_google_' . hash('sha256', $state), ['nonce' => $nonce, 'intent' => $intent], 10 * MINUTE_IN_SECONDS);
        return add_query_arg(['client_id' => $this->clientId(), 'redirect_uri' => $this->callbackUrl(), 'response_type' => 'code', 'scope' => 'openid email profile', 'state' => $state, 'nonce' => $nonce, 'prompt' => 'select_account'], 'https://accounts.google.com/o/oauth2/v2/auth');
    }

    public function authenticate(string $code, string $state) {
        $key = 'qrbuzz_google_' . hash('sha256', $state); $flow = get_transient($key); delete_transient($key);
        if (!$this->configured() || !$code || !$state || !is_array($flow) || empty($flow['nonce'])) { return new \WP_Error('google_state', 'Google authentication could not be verified.'); }
        $response = wp_remote_post('https://oauth2.googleapis.com/token', ['timeout' => 15, 'body' => ['code' => $code, 'client_id' => $this->clientId(), 'client_secret' => $this->clientSecret(), 'redirect_uri' => $this->callbackUrl(), 'grant_type' => 'authorization_code']]);
        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) { return new \WP_Error('google_exchange', 'Google authentication could not be completed.'); }
        $tokens = json_decode(wp_remote_retrieve_body($response), true); $idToken = (string) ($tokens['id_token'] ?? '');
        if ($idToken === '') { return new \WP_Error('google_token', 'Google did not return an identity token.'); }
        // Google's tokeninfo service validates the JWT signature, issuer, expiry and encoded claims server-side.
        $verified = wp_remote_get('https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken), ['timeout' => 15]);
        if (is_wp_error($verified) || wp_remote_retrieve_response_code($verified) !== 200) { return new \WP_Error('google_invalid', 'Google identity validation failed.'); }
        $claims = json_decode(wp_remote_retrieve_body($verified), true);
        $issuer = (string) ($claims['iss'] ?? ''); $audience = (string) ($claims['aud'] ?? ''); $expiry = (int) ($claims['exp'] ?? 0);
        if (!in_array($issuer, ['https://accounts.google.com', 'accounts.google.com'], true) || !hash_equals($this->clientId(), $audience) || $expiry <= time() || !hash_equals((string) $flow['nonce'], (string) ($claims['nonce'] ?? '')) || empty($claims['sub'])) { return new \WP_Error('google_claims', 'Google identity validation failed.'); }
        return ['sub' => sanitize_text_field((string) $claims['sub']), 'email' => sanitize_email((string) ($claims['email'] ?? '')), 'email_verified' => filter_var($claims['email_verified'] ?? false, FILTER_VALIDATE_BOOLEAN), 'given_name' => sanitize_text_field((string) ($claims['given_name'] ?? '')), 'family_name' => sanitize_text_field((string) ($claims['family_name'] ?? '')), 'intent' => sanitize_key((string) ($flow['intent'] ?? 'login'))];
    }

    private function clientId(): string { return trim((string) (defined('QR_BUZZ_GOOGLE_CLIENT_ID') ? QR_BUZZ_GOOGLE_CLIENT_ID : getenv('QR_BUZZ_GOOGLE_CLIENT_ID'))); }
    private function clientSecret(): string { return trim((string) (defined('QR_BUZZ_GOOGLE_CLIENT_SECRET') ? QR_BUZZ_GOOGLE_CLIENT_SECRET : getenv('QR_BUZZ_GOOGLE_CLIENT_SECRET'))); }
}
