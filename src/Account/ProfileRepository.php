<?php
namespace QRBuzz\Account;

use QRBuzz\Database\Schema;

class ProfileRepository {

    public function ensure(int $userId, bool $termsAccepted = false): void {
        global $wpdb;
        $now = current_time('mysql');
        $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::profilesTable() . ' WHERE user_id = %d', $userId));
        if ($exists > 0) { return; }
        $wpdb->insert(Schema::profilesTable(), ['user_id' => $userId, 'email_verified' => 0, 'onboarding_status' => 'pending', 'onboarding_step' => 'plan', 'terms_accepted_at' => $termsAccepted ? $now : null, 'created_at' => $now, 'updated_at' => $now], ['%d','%d','%s','%s','%s','%s','%s']);
    }

    public function profile(int $userId): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::profilesTable() . ' WHERE user_id = %d LIMIT 1', $userId)); }

    public function setVerificationToken(int $userId, string $token): void {
        global $wpdb;
        $this->ensure($userId);
        $wpdb->update(Schema::profilesTable(), ['verification_token_hash' => hash('sha256', $token), 'verification_sent_at' => current_time('mysql'), 'updated_at' => current_time('mysql')], ['user_id' => $userId], ['%s','%s','%s'], ['%d']);
    }

    public function verifyByToken(int $userId, string $token): bool {
        global $wpdb;
        $profile = $this->profile($userId);
        if (!$profile || empty($profile->verification_token_hash) || !hash_equals((string) $profile->verification_token_hash, hash('sha256', $token))) { return false; }
        return $wpdb->update(Schema::profilesTable(), ['email_verified' => 1, 'verification_token_hash' => null, 'updated_at' => current_time('mysql')], ['user_id' => $userId], ['%d','%s','%s'], ['%d']) !== false;
    }

    public function updateOnboarding(int $userId, string $status, string $step): void { global $wpdb; $this->ensure($userId); $wpdb->update(Schema::profilesTable(), ['onboarding_status' => sanitize_key($status), 'onboarding_step' => sanitize_key($step), 'updated_at' => current_time('mysql')], ['user_id' => $userId], ['%s','%s','%s'], ['%d']); }
    public function markLogin(int $userId): void { global $wpdb; $this->ensure($userId); $wpdb->update(Schema::profilesTable(), ['last_login_at' => current_time('mysql'), 'updated_at' => current_time('mysql')], ['user_id' => $userId], ['%s','%s'], ['%d']); }
}
