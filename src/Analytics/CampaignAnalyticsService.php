<?php
namespace QRBuzz\Analytics;

use QRBuzz\Database\Schema;
use QRBuzz\Workspace\WorkspaceService;

class CampaignAnalyticsService {

    private UserAgentParser $parser;
    private WorkspaceService $workspaces;

    public function __construct(?UserAgentParser $parser = null, ?WorkspaceService $workspaces = null) {
        $this->parser = $parser ?: new UserAgentParser();
        $this->workspaces = $workspaces ?: new WorkspaceService();
    }

    public function stats(int $campaignId): array {
        return ['total_qr_codes' => $this->qrCount($campaignId), 'dynamic_qr_codes' => $this->qrCount($campaignId, true), 'static_qr_codes' => $this->qrCount($campaignId, false), 'total_scans' => $this->scanCount($campaignId), 'scans_today' => $this->scanCount($campaignId, DateRange::fromRequest('today')), 'scans_last_7_days' => $this->scanCount($campaignId, DateRange::fromRequest('7days')), 'scans_last_30_days' => $this->scanCount($campaignId, DateRange::fromRequest('30days')), 'previous_7_days' => $this->previousPeriodCount($campaignId, 7), 'top_qr_code' => $this->topQrCodes($campaignId, DateRange::fromRequest('all'), 1)[0] ?? null];
    }

    public function qrCount(int $campaignId, ?bool $trackable = null): int {
        global $wpdb;
        $where = ' WHERE workspace_id = %d AND campaign_id = %d';
        $params = [$this->workspaceId(), $campaignId];
        if ($trackable !== null) { $where .= ' AND is_trackable = %d'; $params[] = $trackable ? 1 : 0; }
        return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::qrcodesTable() . $where, ...$params));
    }

    public function scanCount(int $campaignId, ?DateRange $range = null): int { global $wpdb; [$where, $params] = $this->scanWhere($campaignId, $range); return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(s.id) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where, ...$params)); }

    public function scanCountsByDay(int $campaignId, DateRange $range): array {
        global $wpdb;
        $days = $range->daysForTrend();
        $startTimestamp = strtotime('-' . ($days - 1) . ' days', current_time('timestamp'));
        $start = gmdate('Y-m-d 00:00:00', $startTimestamp);
        $end = gmdate('Y-m-d H:i:s', current_time('timestamp'));
        $rows = $wpdb->get_results($wpdb->prepare('SELECT DATE(s.scanned_at) AS scan_date, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE q.workspace_id = %d AND q.campaign_id = %d AND s.scanned_at BETWEEN %s AND %s GROUP BY DATE(s.scanned_at) ORDER BY scan_date ASC', $this->workspaceId(), $campaignId, $start, $end));
        $counts = [];
        foreach ($rows ?: [] as $row) { $counts[(string) $row->scan_date] = (int) $row->scan_count; }
        $series = [];
        for ($i = 0; $i < $days; $i++) { $date = gmdate('Y-m-d', strtotime('+' . $i . ' days', $startTimestamp)); $series[] = ['date' => $date, 'scans' => $counts[$date] ?? 0]; }
        return $series;
    }

    public function topQrCodes(int $campaignId, DateRange $range, int $limit = 10): array { global $wpdb; [$where, $params] = $this->scanWhere($campaignId, $range); $params[] = $limit; return $wpdb->get_results($wpdb->prepare('SELECT q.id, q.name, q.destination_url, q.shortcode, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan FROM ' . Schema::qrcodesTable() . ' q INNER JOIN ' . Schema::scansTable() . ' s ON s.qr_id = q.id ' . $where . ' GROUP BY q.id, q.name, q.destination_url, q.shortcode ORDER BY scan_count DESC, last_scan DESC LIMIT %d', ...$params)) ?: []; }
    public function recentScans(int $campaignId, DateRange $range, int $limit = 20): array { global $wpdb; [$where, $params] = $this->scanWhere($campaignId, $range); $params[] = $limit; $rows = $wpdb->get_results($wpdb->prepare('SELECT s.*, q.name AS qr_name, q.shortcode FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where . ' ORDER BY s.scanned_at DESC LIMIT %d', ...$params)) ?: []; foreach ($rows as $row) { $row->user_agent_summary = $this->parser->summary((string) $row->user_agent); } return $rows; }
    public function referrerBreakdown(int $campaignId, DateRange $range): array { global $wpdb; [$where, $params] = $this->scanWhere($campaignId, $range); return array_map(static fn($row): array => ['label' => $row->referrer ?: 'Direct / unknown', 'count' => (int) $row->scan_count], $wpdb->get_results($wpdb->prepare('SELECT s.referrer, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where . ' GROUP BY s.referrer ORDER BY scan_count DESC LIMIT 10', ...$params)) ?: []); }
    public function deviceBreakdown(int $campaignId, DateRange $range): array { return $this->breakdown($campaignId, $range, 'deviceType'); }
    public function browserBreakdown(int $campaignId, DateRange $range): array { return $this->breakdown($campaignId, $range, 'browserFamily'); }
    public function previousPeriodCount(int $campaignId, int $days): int { global $wpdb; $end = gmdate('Y-m-d 23:59:59', strtotime('-' . $days . ' days', current_time('timestamp'))); $start = gmdate('Y-m-d 00:00:00', strtotime('-' . ($days * 2 - 1) . ' days', current_time('timestamp'))); return (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(s.id) FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id WHERE q.workspace_id = %d AND q.campaign_id = %d AND s.scanned_at BETWEEN %s AND %s', $this->workspaceId(), $campaignId, $start, $end)); }

    private function breakdown(int $campaignId, DateRange $range, string $parserMethod): array { global $wpdb; [$where, $params] = $this->scanWhere($campaignId, $range); $rows = $wpdb->get_results($wpdb->prepare('SELECT s.user_agent FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id ' . $where, ...$params)) ?: []; $counts = []; foreach ($rows as $row) { $label = $this->parser->{$parserMethod}((string) $row->user_agent); $counts[$label] = ($counts[$label] ?? 0) + 1; } arsort($counts); return array_map(static fn(string $label, int $count): array => ['label' => $label, 'count' => $count], array_keys($counts), array_values($counts)); }
    private function scanWhere(int $campaignId, ?DateRange $range): array { $where = ' WHERE q.workspace_id = %d AND q.campaign_id = %d'; $params = [$this->workspaceId(), $campaignId]; if ($range && $range->start && $range->end) { $where .= ' AND s.scanned_at BETWEEN %s AND %s'; $params[] = $range->start; $params[] = $range->end; } return [$where, $params]; }
    private function workspaceId(): int { return $this->workspaces->id(); }
}
