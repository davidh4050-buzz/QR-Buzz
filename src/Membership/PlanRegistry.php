<?php
namespace QRBuzz\Membership;

class PlanRegistry {

    public function plans(): array {
        $plans = [
            'free' => [
                'label' => 'Free',
                'features' => [
                    'static_qr' => true,
                    'dynamic_qr' => true,
                    'basic_analytics' => true,
                    'advanced_analytics' => false,
                    'insights' => true,
                    'campaigns' => true,
                    'smart_destinations' => false,
                    'qr_styling' => true,
                    'brand_kit' => false,
                    'advanced_branding' => false,
                    'logo_embedding' => false,
                    'csv_export' => false,
                    'api_access' => false,
                    'team_management' => false,
                    'white_label' => false,
                ],
                'limits' => [
                    'qr_assets' => 25,
                    'dynamic_qr_assets' => 10,
                    'campaigns' => 3,
                    'team_members' => 1,
                    'smart_rules_per_asset' => 0,
                    'analytics_retention_days' => 30,
                ],
            ],
            'pro' => [
                'label' => 'Pro',
                'features' => [
                    'static_qr' => true,
                    'dynamic_qr' => true,
                    'basic_analytics' => true,
                    'advanced_analytics' => true,
                    'insights' => true,
                    'campaigns' => true,
                    'smart_destinations' => true,
                    'qr_styling' => true,
                    'brand_kit' => true,
                    'advanced_branding' => true,
                    'logo_embedding' => true,
                    'csv_export' => true,
                    'api_access' => true,
                    'team_management' => false,
                    'white_label' => false,
                ],
                'limits' => [
                    'qr_assets' => 500,
                    'dynamic_qr_assets' => 250,
                    'campaigns' => 50,
                    'team_members' => 1,
                    'smart_rules_per_asset' => 10,
                    'analytics_retention_days' => 365,
                ],
            ],
            'business' => [
                'label' => 'Business',
                'features' => [
                    'static_qr' => true,
                    'dynamic_qr' => true,
                    'basic_analytics' => true,
                    'advanced_analytics' => true,
                    'insights' => true,
                    'campaigns' => true,
                    'smart_destinations' => true,
                    'qr_styling' => true,
                    'brand_kit' => true,
                    'advanced_branding' => true,
                    'logo_embedding' => true,
                    'csv_export' => true,
                    'api_access' => true,
                    'team_management' => true,
                    'white_label' => true,
                ],
                'limits' => [
                    'qr_assets' => null,
                    'dynamic_qr_assets' => null,
                    'campaigns' => null,
                    'team_members' => 10,
                    'smart_rules_per_asset' => 50,
                    'analytics_retention_days' => null,
                ],
            ],
        ];

        return apply_filters('qrbuzz_plans', $plans);
    }

    public function plan(string $planKey): array {
        $plans = $this->plans();
        $planKey = sanitize_key($planKey);
        return $plans[$planKey] ?? $plans['free'];
    }

    public function label(string $planKey): string {
        $plan = $this->plan($planKey);
        return (string) ($plan['label'] ?? ucfirst($planKey));
    }

    public function keys(): array {
        return array_keys($this->plans());
    }
}
