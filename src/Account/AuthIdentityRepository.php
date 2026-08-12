<?php
namespace QRBuzz\Account;

use QRBuzz\Database\Schema;

class AuthIdentityRepository {
    public function find(string $provider, string $subject): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::authIdentitiesTable() . ' WHERE provider = %s AND provider_subject = %s LIMIT 1', sanitize_key($provider), $subject)); }
    public function forUser(int $userId): array { global $wpdb; return $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::authIdentitiesTable() . ' WHERE user_id = %d ORDER BY provider', $userId)) ?: []; }
    public function link(int $userId, string $provider, string $subject, string $email = ''): bool {
        global $wpdb; $now = current_time('mysql');
        $existing = $this->find($provider, $subject);
        if ($existing) { return (int) $existing->user_id === $userId; }
        return $wpdb->insert(Schema::authIdentitiesTable(), ['user_id' => $userId, 'provider' => sanitize_key($provider), 'provider_subject' => sanitize_text_field($subject), 'provider_email' => sanitize_email($email), 'created_at' => $now, 'updated_at' => $now, 'last_login_at' => $now], ['%d','%s','%s','%s','%s','%s','%s']) !== false;
    }
    public function markLogin(int $id): void { global $wpdb; $wpdb->update(Schema::authIdentitiesTable(), ['last_login_at' => current_time('mysql'), 'updated_at' => current_time('mysql')], ['id' => $id], ['%s','%s'], ['%d']); }
    public function hasProvider(int $userId, string $provider): bool { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::authIdentitiesTable() . ' WHERE user_id = %d AND provider = %s', $userId, sanitize_key($provider))) > 0; }
}
