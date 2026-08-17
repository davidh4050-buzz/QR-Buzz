<?php
namespace QRBuzz\Billing;

use QRBuzz\Database\WorkspaceRepository;
use QRBuzz\Platform\PlatformEventRepository;
use QRBuzz\Platform\WebhookLogRepository;

class StripeService {

    private SubscriptionRepository $subscriptions;
    private WorkspaceRepository $workspaces;
    private WebhookLogRepository $webhookLogs;
    private PlatformEventRepository $events;
    private BillingPlanRegistry $billingPlans;

    public function __construct(?SubscriptionRepository $subscriptions = null, ?WorkspaceRepository $workspaces = null, ?WebhookLogRepository $webhookLogs = null, ?PlatformEventRepository $events = null, ?BillingPlanRegistry $billingPlans = null) {
        $this->subscriptions = $subscriptions ?: new SubscriptionRepository();
        $this->workspaces = $workspaces ?: new WorkspaceRepository();
        $this->webhookLogs = $webhookLogs ?: new WebhookLogRepository();
        $this->events = $events ?: new PlatformEventRepository();
        $this->billingPlans = $billingPlans ?: new BillingPlanRegistry();
    }

    public function configured(): bool { return $this->secretKey() !== '' && ($this->billingPlans->priceId('pro') !== '' || $this->billingPlans->priceId('business') !== ''); }
    public function portalAvailable(): bool { return $this->secretKey() !== ''; }
    public function mode(): string { return $this->billingPlans->stripeMode(); }
    public function webhookConfigured(): bool { return $this->webhookSecret() !== ''; }
    public function priceConfigured(string $planKey): bool { return $this->billingPlans->priceId($planKey) !== ''; }

    public function createCheckoutSession(int $workspaceId, int $userId, string $planKey, string $returnTo = 'billing') {
        $planKey = sanitize_key($planKey);
        $price = $this->billingPlans->priceId($planKey);
        if (!$this->billingPlans->paidPlan($planKey) || $this->secretKey() === '' || $price === '') { return new \WP_Error('stripe_not_configured', 'Stripe checkout is not configured for that plan.'); }
        $user = get_user_by('id', $userId);
        if (!$user) { return new \WP_Error('missing_user', 'Unable to start checkout.'); }
        $returnTo = in_array($returnTo, ['billing', 'analytics'], true) ? $returnTo : 'billing';
        $body = [
            'mode' => 'subscription',
            'success_url' => add_query_arg(['checkout' => 'success', 'plan' => $planKey, 'return_to' => $returnTo], home_url('/app/settings/billing')),
            'cancel_url' => add_query_arg(['checkout' => 'cancelled', 'upgrade' => $planKey, 'return_to' => $returnTo], home_url('/app/settings/billing')),
            'client_reference_id' => (string) $workspaceId,
            'metadata[workspace_id]' => (string) $workspaceId,
            'metadata[user_id]' => (string) $userId,
            'metadata[plan_key]' => $planKey,
            'subscription_data[metadata][workspace_id]' => (string) $workspaceId,
            'subscription_data[metadata][plan_key]' => $planKey,
            'line_items[0][price]' => $price,
            'line_items[0][quantity]' => 1,
        ];
        $existing = $this->subscriptions->forWorkspace($workspaceId);
        if ($existing && !empty($existing->stripe_customer_id)) { $body['customer'] = (string) $existing->stripe_customer_id; }
        else { $body['customer_email'] = (string) $user->user_email; }
        $data = $this->apiRequest('POST', '/v1/checkout/sessions', $body);
        if (is_wp_error($data)) { return $data; }
        if (empty($data['url']) || empty($data['id'])) { return new \WP_Error('stripe_checkout_failed', 'Stripe did not return a checkout session.'); }
        $this->subscriptions->upsert($workspaceId, ['user_id' => $userId, 'stripe_checkout_session_id' => (string) $data['id'], 'stripe_price_id' => $price]);
        return (string) $data['url'];
    }

    public function createPortalSession(int $workspaceId) {
        $subscription = $this->subscriptions->forWorkspace($workspaceId);
        if (!$subscription || empty($subscription->stripe_customer_id)) { return new \WP_Error('stripe_portal_unavailable', 'Billing management is not available for this workspace.'); }
        $data = $this->apiRequest('POST', '/v1/billing_portal/sessions', ['customer' => (string) $subscription->stripe_customer_id, 'return_url' => home_url('/app/settings/billing?portal=returned')]);
        if (is_wp_error($data)) { return $data; }
        return !empty($data['url']) ? (string) $data['url'] : new \WP_Error('stripe_portal_failed', 'Stripe did not return a billing portal URL.');
    }

    public function syncSubscription(string $subscriptionId) {
        $subscriptionId = sanitize_text_field($subscriptionId);
        if ($subscriptionId === '') { return new \WP_Error('missing_subscription', 'No Stripe subscription is available to sync.'); }
        $data = $this->apiRequest('GET', '/v1/subscriptions/' . rawurlencode($subscriptionId), []);
        return is_wp_error($data) ? $data : $this->applySubscription($data);
    }

    public function handleWebhook(string $payload, string $signature) {
        $event = $this->verifyEvent($payload, $signature);
        if (is_wp_error($event)) { return $event; }
        $id = (string) ($event['id'] ?? ''); $type = (string) ($event['type'] ?? '');
        $object = $event['data']['object'] ?? [];
        $workspaceId = absint($object['metadata']['workspace_id'] ?? $object['client_reference_id'] ?? 0);
        $this->webhookLogs->received('stripe', $id, $type, ['workspace_id' => $workspaceId, 'stripe_object' => (string) ($object['object'] ?? '')]);
        if (!$id || !$this->subscriptions->recordWebhook('stripe', $id, $type)) { return true; }
        try {
            if ($type === 'checkout.session.completed') { $this->handleCheckout($object); }
            if (str_starts_with($type, 'customer.subscription.')) { $this->handleSubscription($object); }
            if ($type === 'invoice.payment_failed') { $this->handleInvoiceState($object, 'past_due'); }
            if ($type === 'invoice.paid') { $this->handleInvoiceState($object, 'active'); }
            $this->webhookLogs->processed('stripe', $id, 'processed');
        } catch (\Throwable $exception) {
            $this->webhookLogs->processed('stripe', $id, 'failed', $exception->getMessage());
            throw $exception;
        }
        return true;
    }

    public function verifyEvent(string $payload, string $signature) {
        $secret = $this->webhookSecret();
        if (!$secret) { return new \WP_Error('missing_webhook_secret', 'Webhook secret is not configured.'); }
        $timestamp = 0; $signatures = [];
        foreach (explode(',', $signature) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');
            if ($key === 't') { $timestamp = (int) $value; }
            if ($key === 'v1' && $value !== '') { $signatures[] = $value; }
        }
        if (!$timestamp || !$signatures || abs(time() - $timestamp) > 300) { return new \WP_Error('bad_signature', 'Invalid or expired Stripe signature.'); }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        $valid = false;
        foreach ($signatures as $candidate) { if (hash_equals($expected, $candidate)) { $valid = true; break; } }
        if (!$valid) { return new \WP_Error('bad_signature', 'Invalid Stripe signature.'); }
        $event = json_decode($payload, true);
        return is_array($event) ? $event : new \WP_Error('bad_payload', 'Invalid webhook payload.');
    }

    private function handleCheckout(array $session): void {
        $workspaceId = absint($session['metadata']['workspace_id'] ?? $session['client_reference_id'] ?? 0);
        if (!$workspaceId) { throw new \RuntimeException('Checkout session does not identify a workspace.'); }
        $this->subscriptions->upsert($workspaceId, [
            'user_id' => absint($session['metadata']['user_id'] ?? 0),
            'stripe_customer_id' => (string) ($session['customer'] ?? ''),
            'stripe_subscription_id' => (string) ($session['subscription'] ?? ''),
            'stripe_checkout_session_id' => (string) ($session['id'] ?? ''),
        ]);
        if (!empty($session['subscription'])) {
            $result = $this->syncSubscription((string) $session['subscription']);
            if (is_wp_error($result)) { throw new \RuntimeException('Checkout subscription sync failed.'); }
        }
    }

    private function applySubscription(array $subscription) {
        $subscriptionId = sanitize_text_field((string) ($subscription['id'] ?? ''));
        $existing = $subscriptionId ? $this->subscriptions->byStripeSubscription($subscriptionId) : null;
        $workspaceId = absint($subscription['metadata']['workspace_id'] ?? ($existing ? $existing->workspace_id : 0));
        if (!$workspaceId && !empty($subscription['customer'])) {
            $existing = $this->subscriptions->byStripeCustomer((string) $subscription['customer']);
            $workspaceId = $existing ? (int) $existing->workspace_id : 0;
        }
        if (!$workspaceId) { return new \WP_Error('unknown_workspace', 'Stripe subscription could not be matched to a workspace.'); }
        $price = $subscription['items']['data'][0]['price'] ?? [];
        $priceId = sanitize_text_field((string) ($price['id'] ?? ''));
        $planKey = $this->billingPlans->planKeyForPrice($priceId);
        if (!$planKey) { return new \WP_Error('unknown_price', 'Stripe subscription uses an unknown price.'); }
        $status = sanitize_key((string) ($subscription['status'] ?? 'incomplete'));
        $this->subscriptions->upsert($workspaceId, [
            'plan_key' => $planKey,
            'status' => $status,
            'stripe_customer_id' => (string) ($subscription['customer'] ?? ''),
            'stripe_subscription_id' => $subscriptionId,
            'stripe_price_id' => $priceId,
            'billing_interval' => sanitize_key((string) ($price['recurring']['interval'] ?? 'month')),
            'current_period_start' => $this->dateFromTimestamp($subscription['current_period_start'] ?? null),
            'current_period_end' => $this->dateFromTimestamp($subscription['current_period_end'] ?? null),
            'cancel_at_period_end' => !empty($subscription['cancel_at_period_end']),
            'last_synced_at' => current_time('mysql'),
        ]);
        $effective = in_array($status, ['active', 'trialing', 'past_due'], true) ? $planKey : 'free';
        $this->workspaces->updatePlan($workspaceId, $effective);
        $this->events->record('subscription_changed', ['workspace_id' => $workspaceId, 'metadata' => ['plan_key' => $planKey, 'status' => $status]]);
        return true;
    }

    private function handleInvoiceState(array $invoice, string $status): void {
        $subscriptionId = (string) ($invoice['subscription'] ?? '');
        $existing = $subscriptionId ? $this->subscriptions->byStripeSubscription($subscriptionId) : null;
        if (!$existing) { return; }
        if ($status === 'past_due') {
            $this->subscriptions->upsert((int) $existing->workspace_id, ['status' => 'past_due', 'last_synced_at' => current_time('mysql')]);
            $this->workspaces->updatePlan((int) $existing->workspace_id, (string) $existing->plan_key);
            $this->events->record('subscription_payment_failed', ['workspace_id' => (int) $existing->workspace_id]);
            return;
        }
        $result = $this->syncSubscription($subscriptionId);
        if (is_wp_error($result)) { throw new \RuntimeException('Invoice subscription sync failed.'); }
    }

    private function apiRequest(string $method, string $path, array $body) {
        if ($this->secretKey() === '') { return new \WP_Error('stripe_not_configured', 'Stripe is not configured.'); }
        $args = ['method' => $method, 'timeout' => 20, 'headers' => ['Authorization' => 'Bearer ' . $this->secretKey()]];
        if ($method !== 'GET') { $args['body'] = $body; }
        $response = wp_remote_request('https://api.stripe.com' . $path, $args);
        if (is_wp_error($response)) { return new \WP_Error('stripe_unavailable', 'Stripe could not be reached.'); }
        $status = wp_remote_retrieve_response_code($response);
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        return $status >= 200 && $status < 300 && is_array($data) ? $data : new \WP_Error('stripe_request_failed', 'Stripe could not complete the request.');
    }

    private function dateFromTimestamp($timestamp): ?string { return $timestamp ? gmdate('Y-m-d H:i:s', (int) $timestamp) : null; }
    private function secretKey(): string { return defined('QR_BUZZ_STRIPE_SECRET_KEY') ? (string) QR_BUZZ_STRIPE_SECRET_KEY : (string) getenv('QR_BUZZ_STRIPE_SECRET_KEY'); }
    private function webhookSecret(): string { return defined('QR_BUZZ_STRIPE_WEBHOOK_SECRET') ? (string) QR_BUZZ_STRIPE_WEBHOOK_SECRET : (string) getenv('QR_BUZZ_STRIPE_WEBHOOK_SECRET'); }
}
