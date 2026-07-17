<?php
namespace QRBuzz\Billing;

use QRBuzz\Database\WorkspaceRepository;

class StripeService {

    private SubscriptionRepository $subscriptions;
    private WorkspaceRepository $workspaces;

    public function __construct(?SubscriptionRepository $subscriptions = null, ?WorkspaceRepository $workspaces = null) {
        $this->subscriptions = $subscriptions ?: new SubscriptionRepository();
        $this->workspaces = $workspaces ?: new WorkspaceRepository();
    }

    public function configured(): bool { return $this->secretKey() !== '' && ($this->priceId('pro') !== '' || $this->priceId('business') !== ''); }

    public function createCheckoutSession(int $workspaceId, int $userId, string $planKey) {
        $planKey = sanitize_key($planKey);
        $price = $this->priceId($planKey);
        if (!$this->secretKey() || !$price || !in_array($planKey, ['pro', 'business'], true)) { return new \WP_Error('stripe_not_configured', 'Stripe test checkout is not configured yet.'); }
        $user = get_user_by('id', $userId); if (!$user) { return new \WP_Error('missing_user', 'Unable to start checkout.'); }
        $success = add_query_arg(['billing' => 'checkout_return'], home_url('/app/onboarding'));
        $cancel = add_query_arg(['step' => 'plan', 'billing' => 'cancelled'], home_url('/app/onboarding'));
        $response = wp_remote_post('https://api.stripe.com/v1/checkout/sessions', [
            'headers' => ['Authorization' => 'Bearer ' . $this->secretKey()],
            'body' => [
                'mode' => 'subscription',
                'success_url' => $success,
                'cancel_url' => $cancel,
                'customer_email' => $user->user_email,
                'client_reference_id' => (string) $workspaceId,
                'metadata[workspace_id]' => (string) $workspaceId,
                'metadata[user_id]' => (string) $userId,
                'metadata[plan_key]' => $planKey,
                'subscription_data[metadata][workspace_id]' => (string) $workspaceId,
                'subscription_data[metadata][plan_key]' => $planKey,
                'line_items[0][price]' => $price,
                'line_items[0][quantity]' => 1,
            ],
            'timeout' => 20,
        ]);
        if (is_wp_error($response)) { return $response; }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (empty($data['url'])) { return new \WP_Error('stripe_checkout_failed', 'Stripe did not return a checkout URL.'); }
        $this->subscriptions->upsert($workspaceId, ['user_id' => $userId, 'plan_key' => $planKey, 'status' => 'incomplete', 'stripe_checkout_session_id' => (string) ($data['id'] ?? '')]);
        return (string) $data['url'];
    }

    public function handleWebhook(string $payload, string $signature) {
        $event = $this->verifyEvent($payload, $signature);
        if (is_wp_error($event)) { return $event; }
        $id = (string) ($event['id'] ?? ''); $type = (string) ($event['type'] ?? '');
        if (!$id || !$this->subscriptions->recordWebhook('stripe', $id, $type)) { return true; }
        $object = $event['data']['object'] ?? [];
        if ($type === 'checkout.session.completed') { $this->handleCheckout($object); }
        if (str_starts_with($type, 'customer.subscription.')) { $this->handleSubscription($object); }
        if ($type === 'invoice.payment_failed') { $this->handleInvoiceState($object, 'past_due'); }
        if ($type === 'invoice.paid') { $this->handleInvoiceState($object, 'active'); }
        return true;
    }

    public function verifyEvent(string $payload, string $signature) {
        $secret = $this->webhookSecret();
        if (!$secret) { return new \WP_Error('missing_webhook_secret', 'Webhook secret is not configured.'); }
        $parts = [];
        foreach (explode(',', $signature) as $part) { [$key, $value] = array_pad(explode('=', trim($part), 2), 2, ''); $parts[$key] = $value; }
        if (empty($parts['t']) || empty($parts['v1'])) { return new \WP_Error('bad_signature', 'Invalid Stripe signature.'); }
        $signed = $parts['t'] . '.' . $payload;
        $expected = hash_hmac('sha256', $signed, $secret);
        if (!hash_equals($expected, $parts['v1'])) { return new \WP_Error('bad_signature', 'Invalid Stripe signature.'); }
        $event = json_decode($payload, true);
        return is_array($event) ? $event : new \WP_Error('bad_payload', 'Invalid webhook payload.');
    }

    private function handleCheckout(array $session): void {
        $workspaceId = absint($session['metadata']['workspace_id'] ?? $session['client_reference_id'] ?? 0); if (!$workspaceId) { return; }
        $planKey = sanitize_key((string) ($session['metadata']['plan_key'] ?? 'pro'));
        $this->subscriptions->upsert($workspaceId, ['plan_key' => $planKey, 'status' => 'active', 'stripe_customer_id' => (string) ($session['customer'] ?? ''), 'stripe_subscription_id' => (string) ($session['subscription'] ?? ''), 'stripe_checkout_session_id' => (string) ($session['id'] ?? '')]);
        $this->workspaces->updatePlan($workspaceId, $planKey);
    }

    private function handleSubscription(array $subscription): void {
        $workspaceId = absint($subscription['metadata']['workspace_id'] ?? 0); if (!$workspaceId) { return; }
        $planKey = sanitize_key((string) ($subscription['metadata']['plan_key'] ?? 'pro'));
        $status = sanitize_key((string) ($subscription['status'] ?? 'active'));
        $this->subscriptions->upsert($workspaceId, ['plan_key' => $planKey, 'status' => $status, 'stripe_customer_id' => (string) ($subscription['customer'] ?? ''), 'stripe_subscription_id' => (string) ($subscription['id'] ?? ''), 'current_period_start' => $this->dateFromTimestamp($subscription['current_period_start'] ?? null), 'current_period_end' => $this->dateFromTimestamp($subscription['current_period_end'] ?? null), 'cancel_at_period_end' => !empty($subscription['cancel_at_period_end'])]);
        $this->workspaces->updatePlan($workspaceId, in_array($status, ['trialing', 'active'], true) ? $planKey : 'free');
    }

    private function handleInvoiceState(array $invoice, string $status): void { $subscriptionId = (string) ($invoice['subscription'] ?? ''); if (!$subscriptionId) { return; } }
    private function dateFromTimestamp($timestamp): ?string { return $timestamp ? gmdate('Y-m-d H:i:s', (int) $timestamp) : null; }
    private function secretKey(): string { return defined('QR_BUZZ_STRIPE_SECRET_KEY') ? (string) QR_BUZZ_STRIPE_SECRET_KEY : (string) getenv('QR_BUZZ_STRIPE_SECRET_KEY'); }
    private function webhookSecret(): string { return defined('QR_BUZZ_STRIPE_WEBHOOK_SECRET') ? (string) QR_BUZZ_STRIPE_WEBHOOK_SECRET : (string) getenv('QR_BUZZ_STRIPE_WEBHOOK_SECRET'); }
    private function priceId(string $planKey): string { $constant = 'QR_BUZZ_STRIPE_' . strtoupper($planKey) . '_PRICE_ID'; return defined($constant) ? (string) constant($constant) : (string) getenv($constant); }
}
