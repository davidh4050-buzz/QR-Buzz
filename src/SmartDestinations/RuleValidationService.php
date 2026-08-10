<?php
namespace QRBuzz\SmartDestinations;

use QRBuzz\Utils\DateTimeHelper;

class RuleValidationService {
    public function validate(array $input): array {
        $errors = [];
        $name = trim((string) ($input['name'] ?? ''));
        $url = trim((string) ($input['destination_url'] ?? ''));
        if ($name === '') { $errors['name'] = 'Enter a rule name.'; }
        if ($url === '' || !wp_http_validate_url($url) || !in_array(strtolower((string) wp_parse_url($url, PHP_URL_SCHEME)), ['http','https'], true)) { $errors['destination_url'] = 'Enter a valid HTTP or HTTPS destination URL.'; }
        $priority = filter_var($input['priority'] ?? null, FILTER_VALIDATE_INT);
        if ($priority === false || $priority < 1 || $priority > 9999) { $errors['priority'] = 'Priority must be between 1 and 9999.'; }
        $start = DateTimeHelper::localInputToUtc($input['starts_at'] ?? null);
        $end = DateTimeHelper::localInputToUtc($input['ends_at'] ?? null);
        if (!empty($input['starts_at']) && !$start) { $errors['starts_at'] = 'Enter a valid start date and time.'; }
        if (!empty($input['ends_at']) && !$end) { $errors['ends_at'] = 'Enter a valid end date and time.'; }
        if ($start && $end && DateTimeHelper::utcTimestamp($start) > DateTimeHelper::utcTimestamp($end)) { $errors['ends_at'] = 'The end date must not be before the start date.'; }
        foreach (['time_start','time_end'] as $field) { if (!empty($input[$field]) && !preg_match('/^([01][0-9]|2[0-3]):[0-5][0-9]$/', (string) $input[$field])) { $errors[$field] = 'Enter a valid 24-hour time.'; } }
        $days = array_values(array_unique(array_map('absint', (array) ($input['days_of_week'] ?? []))));
        if (array_filter($days, static fn(int $day): bool => $day < 1 || $day > 7)) { $errors['days_of_week'] = 'Select valid days of the week.'; }
        return $errors;
    }

    public function normalize(array $input): array {
        return [
            'name' => sanitize_text_field((string) ($input['name'] ?? '')),
            'destination_url' => esc_url_raw((string) ($input['destination_url'] ?? '')),
            'status' => ($input['status'] ?? 'inactive') === 'active' ? 'active' : 'inactive',
            'priority' => max(1, min(9999, absint($input['priority'] ?? 10))),
            'starts_at' => DateTimeHelper::localInputToUtc($input['starts_at'] ?? null),
            'ends_at' => DateTimeHelper::localInputToUtc($input['ends_at'] ?? null),
            'days_of_week' => array_values(array_unique(array_filter(array_map('absint', (array) ($input['days_of_week'] ?? [])), static fn(int $day): bool => $day >= 1 && $day <= 7))),
            'time_start' => sanitize_text_field((string) ($input['time_start'] ?? '')),
            'time_end' => sanitize_text_field((string) ($input['time_end'] ?? '')),
        ];
    }
}
