<?php
namespace QRBuzz\Analytics;

use QRBuzz\Database\Schema;
use QRBuzz\Workspace\WorkspaceService;

class AnalyticsRepository {

    private UserAgentParser $parser;
    private WorkspaceService $workspaces;

    public function __construct(?UserAgentParser $parser = null, ?WorkspaceService $workspaces = null) {
        $this->parser = $parser ?: new UserAgentParser();
        $this->workspaces = $workspaces ?: new WorkspaceService();
    }

    public function totalQrCodes(): int { global $wpdb; return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . ' WHERE workspace_id = %d', $this->workspaceId())); }

    public function totalScans(?DateRange $range = null): int {
        global $wpdb;
        [$where, $params] = $this->scanWhere($range);
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(s.id) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where, ...$params));
    }

    public function scansToday(): int { return $this->totalScans(DateRange::fromRequest('today')); }
    public function scansLastSevenDays(): int { return $this->totalScans(DateRange::fromRequest('7days')); }

    public function latestScan(?DateRange $range = null): ?string {
        global $wpdb;
        [$where, $params] = $this->scanWhere($range);
        $latest = $wpdb->get_var($wpdb->prepare('SELECT MAX(s.scanned_at) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where, ...$params));
        return $latest ? (string) $latest : null;
    }

    public function scanCountsByDay(DateRange $range): array {
        global $wpdb;
        $days = $range->daysForTrend();
        $startTimestamp = strtotime('-' . ($days - 1) . ' days', current_time('timestamp'));
        $start = gmdate('Y-m-d 00:00:00', $startTimestamp);
        $end = gmdate('Y-m-d H:i:s', current_time('timestamp'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT DATE(s.scanned_at) AS scan_date, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE q.workspace_id = %d AND s.scanned_at BETWEEN %s AND %s GROUP BY DATE(s.scanned_at) ORDER BY scan_date ASC', $this->workspaceId(), $start, $end));
        $counts = [];
        foreach ($rows ?: [] as $row) { $counts[(string) $row->scan_date] = (int) $row->scan_count; }
        $series = [];
        for ($i = 0; $i < $days; $i++) { $date = gmdate('Y-m-d', strtotime('+' . $i . ' days', $startTimestamp)); $series[] = ['date' => $date, 'scans' => $counts[$date] ?? 0]; }
        return $series;
    }

    /** @return object[] */
    public function topQrCodes(DateRange $range, int $limit = 10): array {
        global $wpdb;
        [$where, $params] = $this->scanWhere($range);
        $params[] = $limit;
        return $wpdb->get_results($wpdb->prepare('SELECT q.id, q.name, q.destination_url, q.shortcode, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan FROM ' . Schema::qrcodesTable() . ' q INNER JOIN ' . Schema::scansTable() . ' s ON s.qr_id = q.id ' . $where . ' GROUP BY q.id, q.name, q.destination_url, q.shortcode ORDER BY scan_count DESC, last_scan DESC LIMIT %d', ...$params)) ?: [];
    }

    /** @return object[] */
    public function recentScans(DateRange $range, int $limit = 20): array {
        global $wpdb;
        [$where, $params] = $this->scanWhere($range);
        $params[] = $limit;
        $rows = $wpdb->get_results($wpdb->prepare('SELECT s.*, q.name AS qr_name, q.shortcode FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where . ' ORDER BY s.scanned_at DESC LIMIT %d', ...$params)) ?: [];
        foreach ($rows as $row) { $row->user_agent_summary = $this->parser->summary((string) $row->user_agent); }
        return $rows;
    }

    public function deviceBreakdown(DateRange $range): array { return $this->breakdown($range, 'deviceType'); }
    public function browserBreakdown(DateRange $range): array { return $this->breakdown($range, 'browserFamily'); }
    public function mostScanned(DateRange $range): ?object { $top = $this->topQrCodes($range, 1); return $top[0] ?? null; }

    public function dashboardStats(DateRange $range): array {
        return ['total_qr_codes' => $this->totalQrCodes(), 'total_scans' => $this->totalScans($range), 'scans_today' => $this->scansToday(), 'scans_last_7_days' => $this->scansLastSevenDays(), 'most_scanned' => $this->mostScanned($range), 'latest_scan' => $this->latestScan($range)];
    }

    private function breakdown(DateRange $range, string $parserMethod): array {
        global $wpdb;
        [$where, $params] = $this->scanWhere($range);
        $rows = $wpdb->get_results($wpdb->prepare('SELECT s.user_agent FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where, ...$params)) ?: [];
        $counts = [];
        foreach ($rows as $row) { $label = $this->parser->{$parserMethod}((string) $row->user_agent); $counts[$label] = ($counts[$label] ?? 0) + 1; }
        arsort($counts);
        return array_map(static fn(string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($counts), array_values($counts));
    }

    private function scanWhere(?DateRange $range): array {
        $where = ' WHERE q.workspace_id = %d';
        $params = [$this->workspaceId()];
        if ($range && $range->start && $range->end) { $where .= ' AND s.scanned_at BETWEEN %s AND %s'; $params[] = $range->start; $params[] = $range->end; }
        return [$where, $params];
    }

    private function workspaceId(): int { return $this->workspaces->id(); }
}
