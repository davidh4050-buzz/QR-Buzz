<?php
namespace QRBuzz\Analytics;

class DateRange {

    public string $key;
    public string $label;
    public ?string $start;
    public ?string $end;

    public function __construct(string $key, string $label, ?string $start, ?string $end) {
        $this->key = $key;
        $this->label = $label;
        $this->start = $start;
        $this->end = $end;
    }

    public static function fromRequest(?string $key): self {
        $key = sanitize_key((string) $key);
        $now = current_time('timestamp');
        $end = gmdate('Y-m-d H:i:s', $now);

        if ($key === 'today') {
            return new self('today', 'Today', gmdate('Y-m-d 00:00:00', $now), $end);
        }

        if ($key === '7days') {
            return new self('7days', 'Last 7 days', gmdate('Y-m-d 00:00:00', strtotime('-6 days', $now)), $end);
        }

        if ($key === '30days') {
            return new self('30days', 'Last 30 days', gmdate('Y-m-d 00:00:00', strtotime('-29 days', $now)), $end);
        }

        return new self('all', 'All time', null, null);
    }

    public static function options(): array {
        return [
            'today' => 'Today',
            '7days' => 'Last 7 days',
            '30days' => 'Last 30 days',
            'all' => 'All time',
        ];
    }

    public function daysForTrend(): int {
        if ($this->key === 'today') {
            return 1;
        }

        if ($this->key === '7days') {
            return 7;
        }

        return 30;
    }
}
