<?php
namespace QRBuzz\Billing;

use QRBuzz\Database\Schema;

class SubscriptionRepository {

    public function ensureFree(int $workspaceId, int $userId = 0): void {
        if ($this->forWorkspace($workspaceId)) { return; }
        $this->upsert($workspaceId, ['user_id' => $userId, 'plan_key' => 'free', 'status' => 'free']);
    }

    public function forWorkspace(int $workspaceId): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::subscriptionsTable() . ' WHERE workspace_id = %d LIMIT 1', $workspaceId)); }
    public function byStripeCustomer(string $customerId): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::subscriptionsTable() . ' WHERE stripe_customer_id = %s LIMIT 1', $customerId)); }
    public function byStripeSubscription(string $subscriptionId): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::subscriptionsTable() . ' WHERE stripe_subscription_id = %s LIMIT 1', $subscriptionId)); }
    public function byCheckoutSession(string $sessionId): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::subscriptionsTable() . ' WHERE stripe_checkout_session_id = %s LIMIT 1', $sessionId)); }

    public function upsert(int $workspaceId, array $data): bool {
        global $wpdb;
        $now = current_time('mysql');
        $existing = $this->forWorkspace($workspaceId);
        $previous = static function(string $field, $fallback = null) use ($existing) {
            return $existing && property_exists($existing, $field) ? $existing->{$field} : $fallback;
        };
        $row = [
            'workspace_id' => $workspaceId,
            'user_id' => isset($data['user_id']) ? absint($data['user_id']) : (int) $previous('user_id', 0),
            'plan_key' => sanitize_key((string) ($data['plan_key'] ?? $previous('plan_key', 'free'))),
            'status' => sanitize_key((string) ($data['status'] ?? $previous('status', 'free'))),
            'stripe_customer_id' => array_key_exists('stripe_customer_id', $data) ? sanitize_text_field((string) $data['stripe_customer_id']) : $previous('stripe_customer_id'),
            'stripe_subscription_id' => array_key_exists('stripe_subscription_id', $data) ? sanitize_text_field((string) $data['stripe_subscription_id']) : $previous('stripe_subscription_id'),
            'stripe_checkout_session_id' => array_key_exists('stripe_checkout_session_id', $data) ? sanitize_text_field((string) $data['stripe_checkout_session_id']) : $previous('stripe_checkout_session_id'),
            'stripe_price_id' => array_key_exists('stripe_price_id', $data) ? sanitize_text_field((string) $data['stripe_price_id']) : $previous('stripe_price_id'),
            'billing_interval' => array_key_exists('billing_interval', $data) ? sanitize_key((string) $data['billing_interval']) : $previous('billing_interval'),
            'current_period_start' => array_key_exists('current_period_start', $data) ? $data['current_period_start'] : $previous('current_period_start'),
            'current_period_end' => array_key_exists('current_period_end', $data) ? $data['current_period_end'] : $previous('current_period_end'),
            'cancel_at_period_end' => array_key_exists('cancel_at_period_end', $data) ? (!empty($data['cancel_at_period_end']) ? 1 : 0) : (int) $previous('cancel_at_period_end', 0),
            'last_synced_at' => array_key_exists('last_synced_at', $data) ? $data['last_synced_at'] : $previous('last_synced_at'),
            'updated_at' => $now,
        ];
        $formats = ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s'];
        if ($existing) { return $wpdb->update(Schema::subscriptionsTable(), $row, ['workspace_id' => $workspaceId], $formats, ['%d']) !== false; }
        $row['created_at'] = $now;
        $formats[] = '%s';
        return $wpdb->insert(Schema::subscriptionsTable(), $row, $formats) !== false;
    }

    public function recordWebhook(string $provider, string $eventId, string $eventType): bool {
        global $wpdb;
        $result = $wpdb->insert(Schema::webhookEventsTable(), ['provider' => sanitize_key($provider), 'event_id' => sanitize_text_field($eventId), 'event_type' => sanitize_text_field($eventType), 'processed_at' => current_time('mysql')], ['%s','%s','%s','%s']);
        return $result !== false;
    }
}
