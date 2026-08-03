<?php
function absint($value): int { return abs((int) $value); }
function sanitize_text_field($value): string { return trim(strip_tags((string) $value)); }
function esc_url_raw($value): string { return filter_var((string) $value, FILTER_SANITIZE_URL); }
function wp_http_validate_url($value) { return filter_var($value, FILTER_VALIDATE_URL); }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function wp_timezone(): DateTimeZone { return new DateTimeZone('Europe/London'); }
function wp_date(string $format, ?int $timestamp = null, ?DateTimeZone $timezone = null): string { $date = new DateTimeImmutable('@' . ($timestamp ?? time())); return $date->setTimezone($timezone ?: wp_timezone())->format($format); }
function get_option(string $key) { return $key === 'date_format' ? 'j F Y' : 'H:i'; }
function current_time(string $type, bool $gmt = false) { return $type === 'timestamp' ? time() : gmdate('Y-m-d H:i:s'); }

require dirname(__DIR__) . '/src/Models/DestinationRule.php';
require dirname(__DIR__) . '/src/Utils/DateTimeHelper.php';
require dirname(__DIR__) . '/src/SmartDestinations/RuleConditionFormatter.php';
require dirname(__DIR__) . '/src/SmartDestinations/RuleValidationService.php';
require dirname(__DIR__) . '/src/Redirect/DestinationResolver.php';

$row = (object) ['id'=>1,'workspace_id'=>1,'qr_id'=>1,'name'=>'Night','priority'=>1,'status'=>'active','destination_url'=>'https://example.com/night','conditions_json'=>'{}','starts_at'=>null,'ends_at'=>null,'days_of_week'=>null,'time_start'=>'17:00','time_end'=>'02:00','created_at'=>'2026-08-03 00:00:00','updated_at'=>'2026-08-03 00:00:00'];
$rule = QRBuzz\Models\DestinationRule::fromRow($row);
$formatter = new QRBuzz\SmartDestinations\RuleConditionFormatter();
assert(str_contains($formatter->format($rule), 'overnight'));
$validator = new QRBuzz\SmartDestinations\RuleValidationService();
assert(isset($validator->validate(['name'=>'Unsafe','destination_url'=>'javascript:alert(1)','priority'=>1])['destination_url']));
$reflection = new ReflectionClass(QRBuzz\Redirect\DestinationResolver::class);
$resolver = $reflection->newInstanceWithoutConstructor();
assert($resolver->ruleMatches($rule, (new DateTimeImmutable('2026-08-03 23:00', wp_timezone()))->getTimestamp()));
assert($resolver->ruleMatches($rule, (new DateTimeImmutable('2026-08-04 01:00', wp_timezone()))->getTimestamp()));
assert(!$resolver->ruleMatches($rule, (new DateTimeImmutable('2026-08-04 12:00', wp_timezone()))->getTimestamp()));
echo "Smart Destinations smoke tests passed.\n";
