<?php
namespace QRBuzz\Billing;

class BillingPlanRegistry {

    public function plans(): array {
        return [
            'free' => [
                'key' => 'free',
                'label' => 'Free',
                'description' => 'For getting started with QR Buzz.',
                'interval' => null,
                'display_price' => '£0',
                'features' => ['25 QR codes', '10 dynamic QR codes', 'Basic analytics', '3 campaigns'],
            ],
            'pro' => [
                'key' => 'pro',
                'label' => 'Pro',
                'description' => 'For professionals who need more control and reporting.',
                'interval' => 'month',
                'display_price' => $this->displayPrice('pro'),
                'features' => ['500 QR codes', 'Advanced analytics', 'CSV analytics export', 'Smart Destinations', 'Advanced branding'],
            ],
            'business' => [
                'key' => 'business',
                'label' => 'Business',
                'description' => 'For organisations and higher-volume QR programmes.',
                'interval' => 'month',
                'display_price' => $this->displayPrice('business'),
                'features' => ['Unlimited QR codes', 'Advanced analytics', 'CSV analytics export', '50 Smart Destination rules per QR', 'Business branding controls'],
            ],
        ];
    }

    public function plan(string $planKey): array {
        $plans = $this->plans();
        $planKey = sanitize_key($planKey);
        return $plans[$planKey] ?? $plans['free'];
    }

    public function paidPlan(string $planKey): bool {
        return in_array(sanitize_key($planKey), ['pro', 'business'], true);
    }

    public function priceId(string $planKey): string {
        $planKey = sanitize_key($planKey);
        if (!$this->paidPlan($planKey)) { return ''; }
        $constant = 'QR_BUZZ_STRIPE_' . strtoupper($planKey) . '_PRICE_ID';
        return trim((string) (defined($constant) ? constant($constant) : getenv($constant)));
    }

    public function planKeyForPrice(string $priceId): ?string {
        $priceId = trim($priceId);
        if ($priceId === '') { return null; }
        foreach (['pro', 'business'] as $planKey) {
            $configured = $this->priceId($planKey);
            if ($configured !== '' && hash_equals($configured, $priceId)) { return $planKey; }
        }
        return null;
    }

    public function stripeMode(): string {
        $secret = trim((string) (defined('QR_BUZZ_STRIPE_SECRET_KEY') ? QR_BUZZ_STRIPE_SECRET_KEY : getenv('QR_BUZZ_STRIPE_SECRET_KEY')));
        if (str_starts_with($secret, 'sk_test_')) { return 'test'; }
        if (str_starts_with($secret, 'sk_live_')) { return 'live'; }
        return 'unknown';
    }

    private function displayPrice(string $planKey): string {
        $constant = 'QR_BUZZ_' . strtoupper($planKey) . '_DISPLAY_PRICE';
        $value = trim((string) (defined($constant) ? constant($constant) : getenv($constant)));
        return $value !== '' ? $value : 'Monthly billing';
    }
}
