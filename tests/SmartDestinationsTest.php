<?php
/**
 * Smart Destinations regression cases for the WordPress test suite.
 *
 * @group smart-destinations
 */
class SmartDestinationsTest extends WP_UnitTestCase {
    public function test_validation_rejects_unsafe_urls(): void {
        $service = new \QRBuzz\SmartDestinations\RuleValidationService();
        $errors = $service->validate(['name'=>'Unsafe','destination_url'=>'javascript:alert(1)','priority'=>1]);
        $this->assertArrayHasKey('destination_url', $errors);
    }

    public function test_formatter_never_exposes_condition_json(): void {
        $rule = \QRBuzz\Models\DestinationRule::fromRow((object) ['id'=>1,'workspace_id'=>1,'qr_id'=>1,'name'=>'Weekdays','priority'=>1,'status'=>'active','destination_url'=>'https://example.com','conditions_json'=>'{"internal":true}','starts_at'=>null,'ends_at'=>null,'days_of_week'=>'1,2,3,4,5','time_start'=>'09:00','time_end'=>'17:00','created_at'=>'2026-08-03 00:00:00','updated_at'=>'2026-08-03 00:00:00']);
        $label = (new \QRBuzz\SmartDestinations\RuleConditionFormatter())->format($rule);
        $this->assertSame('Monday–Friday · 09:00–17:00', $label);
        $this->assertStringNotContainsString('{', $label);
    }

    public function test_overnight_windows_are_documented_as_supported(): void {
        $rule = \QRBuzz\Models\DestinationRule::fromRow((object) ['id'=>1,'workspace_id'=>1,'qr_id'=>1,'name'=>'Night','priority'=>1,'status'=>'active','destination_url'=>'https://example.com','conditions_json'=>'{}','starts_at'=>null,'ends_at'=>null,'days_of_week'=>null,'time_start'=>'17:00','time_end'=>'02:00','created_at'=>'2026-08-03 00:00:00','updated_at'=>'2026-08-03 00:00:00']);
        $this->assertStringContainsString('overnight', (new \QRBuzz\SmartDestinations\RuleConditionFormatter())->format($rule));
    }
}
