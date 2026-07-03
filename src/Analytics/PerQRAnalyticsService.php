<?php
namespace QRBuzz\Analytics;

use QRBuzz\Database\Schema;

class PerQRAnalyticsService {

    private UserAgentParser $parser;

    public function __construct(?UserAgentParser $parser = null) {
        $this->parser = $parser ?: new UserAgentParser();
    }

    public function stats(int $qrId): array {
        return [
            'total_scans' => $this->count($qrId),
            'scans_today' => $this->count($qrId, DateRange::fromRequest('today')),
            'scans_last_7_days' => $this->count($qrId, DateRange::fromRequest('7days')),
            'scans_last_30_days' => $this->count($qrId, DateRange::fromRequest('30days')),
            'latest_scan' => $this->latestScan($qrId),
            'previous_7_days' => $this->previousPeriodCount($qrId, 7),
        ];
    }

    public function count(int $qrId, ?DateRange $range = null): int {
        global $wpdb;
        [$where, $params] = $this->where($qrId, $range);
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::scansTable() . $where, ...$params));
    }

    public function latestScan(int $qrId): ?string {
        global $wpdb;
        $latest = $wpdb->get_var($wpdb->prepare('SELECT MAX(scanned_at) FROM ' . Schema::scansTable() . ' WHERE qr_id = %d', $qrId));
        return $latest ? (string) $latest : null;
    }

    public function scanCountsByDay(int $qrId, int $days = 30): array {
        global $wpdb;
        $startTimestamp = strtotime('-' . ($days - 1) . ' days', current_time('timestamp'));
        $start = gmdate('Y-m-d 00:00:00', $startTimestamp);
        $end = gmdate('Y-m-d H:i:s', current_time('timestamp'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT DATE(scanned_at) AS scan_date, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' WHERE qr_id = %d AND scanned_at BETWEEN %s AND %s GROUP BY DATE(scanned_at) ORDER BY scan_date ASC', $qrId, $start, $end));
        $counts = [];
        foreach ($rows ?: [] as $row) { $counts[(string) $row->scan_date] = (int) $row->scan_count; }
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = gmdate('Y-m-d', strtotime('+' . $i . ' days', $startTimestamp));
            $series[] = ['date' => $date, 'scans' => $counts[$date] ?? 0];
        }
        return $series;
    }

    public function recentScans(int $qrId, int $limit = 20): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT * FROM ' . Schema::scansTable() . ' WHERE qr_id = %d ORDER BY scanned_at DESC LIMIT %d', $qrId, $limit)) ?: [];
        foreach ($rows as $row) { $row->user_agent_summary = $this->parser->summary((string) $row->user_agent); }
        return $rows;
    }

    public function referrerBreakdown(int $qrId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT referrer, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' WHERE qr_id = %d GROUP BY referrer ORDER BY scan_count DESC LIMIT 10', $qrId)) ?: [];
        return array_map(static fn($row): array => ['label' => $row->referrer ?: 'Direct / unknown', 'count' => (int) $row->scan_count], $rows);
    }

    public function deviceBreakdown(int $qrId): array { return $this->breakdown($qrId, 'deviceType'); }
    public function browserBreakdown(int $qrId): array { return $this->breakdown($qrId, 'browserFamily'); }

    public function previousPeriodCount(int $qrId, int $days): int {
        global $wpdb;
        $end = gmdate('Y-m-d 23:59:59', strtotime('-' . $days . ' days', current_time('timestamp')));
        $start = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days * 2 - 1) . ' days', current_time('timestamp')));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::scansTable() . ' WHERE qr_id = %d AND scanned_at BETWEEN %s AND %s', $qrId, $start, $end));
    }

    private function breakdown(int $qrId, string $parserMethod): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT user_agent FROM ' . Schema::scansTable() . ' WHERE qr_id = %d', $qrId)) ?: [];
        $counts = [];
        foreach ($rows as $row) {
            $label = $this->parser->{$parserMethod}((string) $row->user_agent);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts);
        return array_map(static fn(string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($counts), array_values($counts));
    }

    private function where(int $qrId, ?DateRange $range): array {
        $where = ' WHERE qr_id = %d';
        $params = [$qrId];
        if ($range && $range->start && $range->end) {
            $where .= ' AND scanned_at BETWEEN %s AND %s';
            $params[] = $range->start;
            $params[] = $range->end;
        }
        return [$where, $params];
    }
}
