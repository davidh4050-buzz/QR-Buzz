<?php
namespace QRBuzz\Analytics;

use QRBuzz\Database\Schema;
use QRBuzz\Workspace\WorkspaceService;

class PerQRAnalyticsService {

    private UserAgentParser $parser;
    private WorkspaceService $workspaces;

    public function __construct(?UserAgentParser $parser = null, ?WorkspaceService $workspaces = null) {
        $this->parser = $parser ?: new UserAgentParser();
        $this->workspaces = $workspaces ?: new WorkspaceService();
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
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(s.id) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where, ...$params));
    }

    public function latestScan(int $qrId): ?string {
        global $wpdb;
        $latest = $wpdb->get_var($wpdb->prepare('SELECT MAX(s.scanned_at) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE s.qr_id = %d AND q.workspace_id = %d', $qrId, $this->workspaceId()));
        return $latest ? (string) $latest : null;
    }

    public function scanCountsByDay(int $qrId, int $days = 30): array {
        global $wpdb;
        $startTimestamp = strtotime('-' . ($days - 1) . ' days', current_time('timestamp'));
        $start = gmdate('Y-m-d 00:00:00', $startTimestamp);
        $end = gmdate('Y-m-d H:i:s', current_time('timestamp'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT DATE(s.scanned_at) AS scan_date, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE s.qr_id = %d AND q.workspace_id = %d AND s.scanned_at BETWEEN %s AND %s GROUP BY DATE(s.scanned_at) ORDER BY scan_date ASC', $qrId, $this->workspaceId(), $start, $end));
        $counts = [];
        foreach ($rows ?: [] as $row) {
            $counts[(string) $row->scan_date] = (int) $row->scan_count;
        }
        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = gmdate('Y-m-d', strtotime('+' . $i . ' days', $startTimestamp));
            $series[] = ['date' => $date, 'scans' => $counts[$date] ?? 0];
        }
        return $series;
    }

    public function recentScans(int $qrId, int $limit = 20): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT s.* FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE s.qr_id = %d AND q.workspace_id = %d ORDER BY s.scanned_at DESC LIMIT %d', $qrId, $this->workspaceId(), $limit)) ?: [];
        foreach ($rows as $row) {
            $row->user_agent_summary = $this->parser->summary((string) $row->user_agent);
        }
        return $rows;
    }

    public function referrerBreakdown(int $qrId): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT s.referrer, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE s.qr_id = %d AND q.workspace_id = %d GROUP BY s.referrer ORDER BY scan_count DESC LIMIT 10', $qrId, $this->workspaceId())) ?: [];
        return array_map(static fn($row): array => ['label' => $row->referrer ?: 'Direct / unknown', 'count' => (int) $row->scan_count], $rows);
    }

    public function deviceBreakdown(int $qrId): array { return $this->breakdown($qrId, 'deviceType'); }
    public function browserBreakdown(int $qrId): array { return $this->breakdown($qrId, 'browserFamily'); }

    public function previousPeriodCount(int $qrId, int $days): int {
        global $wpdb;
        $end = gmdate('Y-m-d 23:59:59', strtotime('-' . $days . ' days', current_time('timestamp')));
        $start = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days * 2 - 1) . ' days', current_time('timestamp')));
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(s.id) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE s.qr_id = %d AND q.workspace_id = %d AND s.scanned_at BETWEEN %s AND %s', $qrId, $this->workspaceId(), $start, $end));
    }

    private function breakdown(int $qrId, string $parserMethod): array {
        global $wpdb;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT s.user_agent FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE s.qr_id = %d AND q.workspace_id = %d', $qrId, $this->workspaceId())) ?: [];
        $counts = [];
        foreach ($rows as $row) {
            $label = $this->parser->{$parserMethod}((string) $row->user_agent);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }
        arsort($counts);
        return array_map(static fn(string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($counts), array_values($counts));
    }

    private function where(int $qrId, ?DateRange $range): array {
        $where = ' WHERE s.qr_id = %d AND q.workspace_id = %d';
        $params = [$qrId, $this->workspaceId()];
        if ($range && $range->start && $range->end) {
            $where .= ' AND s.scanned_at BETWEEN %s AND %s';
            $params[] = $range->start;
            $params[] = $range->end;
        }
        return [$where, $params];
    }

    private function workspaceId(): int { return $this->workspaces->id(); }
}
