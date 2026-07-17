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
    public function byCheckoutSession(string $sessionId): ?object { global $wpdb; return $wpdb->get_row($wpdb->prepare('SELECT * FROM ' . Schema::subscriptionsTable() . ' WHERE stripe_checkout_session_id = %s LIMIT 1', $sessionId)); }

    public function upsert(int $workspaceId, array $data): bool {
        global $wpdb;
        $now = current_time('mysql');
        $existing = $this->forWorkspace($workspaceId);
        $row = [
            'workspace_id' => $workspaceId,
            'user_id' => isset($data['user_id']) ? absint($data['user_id']) : ($existing ? (int) $existing->user_id : null),
            'plan_key' => sanitize_key((string) ($data['plan_key'] ?? ($existing ? $existing->plan_key : 'free'))),
            'status' => sanitize_key((string) ($data['status'] ?? ($existing ? $existing->status : 'free'))),
            'stripe_customer_id' => isset($data['stripe_customer_id']) ? sanitize_text_field((string) $data['stripe_customer_id']) : ($existing->stripe_customer_id ?? null),
            'stripe_subscription_id' => isset($data['stripe_subscription_id']) ? sanitize_text_field((string) $data['stripe_subscription_id']) : ($existing->stripe_subscription_id ?? null),
            'stripe_checkout_session_id' => isset($data['stripe_checkout_session_id']) ? sanitize_text_field((string) $data['stripe_checkout_session_id']) : ($existing->stripe_checkout_session_id ?? null),
            'current_period_start' => $data['current_period_start'] ?? ($existing->current_period_start ?? null),
            'current_period_end' => $data['current_period_end'] ?? ($existing->current_period_end ?? null),
            'cancel_at_period_end' => !empty($data['cancel_at_period_end']) ? 1 : (int) ($existing->cancel_at_period_end ?? 0),
            'updated_at' => $now,
        ];
        if ($existing) { return $wpdb->update(Schema::subscriptionsTable(), $row, ['workspace_id' => $workspaceId], ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s'], ['%d']) !== false; }
        $row['created_at'] = $now;
        return $wpdb->insert(Schema::subscriptionsTable(), $row, ['%d','%d','%s','%s','%s','%s','%s','%s','%s','%d','%s','%s']) !== false;
    }

    public function recordWebhook(string $provider, string $eventId, string $eventType): bool {
        global $wpdb;
        $result = $wpdb->insert(Schema::webhookEventsTable(), ['provider' => sanitize_key($provider), 'event_id' => sanitize_text_field($eventId), 'event_type' => sanitize_text_field($eventType), 'processed_at' => current_time('mysql')], ['%s','%s','%s','%s']);
        return $result !== false;
    }
}
