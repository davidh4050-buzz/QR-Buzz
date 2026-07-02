<?php
namespace QRBuzz\Analytics;

use QRBuzz\Database\Schema;

class AnalyticsRepository {

    private UserAgentParser $parser;

    public function __construct(?UserAgentParser $parser = null) {
        $this->parser = $parser ?: new UserAgentParser();
    }

    public function totalQrCodes(): int {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::qrcodesTable());
    }

    public function totalScans(?DateRange $range = null): int {
        global $wpdb;

        $where = $this->dateWhere($range);
        $params = $this->dateParams($range);
        $sql = 'SELECT COUNT(*) FROM ' . Schema::scansTable() . $where;

        if (!$params) {
            return (int) $wpdb->get_var($sql);
        }

        return (int) $wpdb->get_var($wpdb->prepare($sql, ...$params));
    }

    public function scansToday(): int {
        return $this->totalScans(DateRange::fromRequest('today'));
    }

    public function scansLastSevenDays(): int {
        return $this->totalScans(DateRange::fromRequest('7days'));
    }

    public function latestScan(?DateRange $range = null): ?string {
        global $wpdb;

        $where = $this->dateWhere($range);
        $params = $this->dateParams($range);
        $sql = 'SELECT MAX(scanned_at) FROM ' . Schema::scansTable() . $where;
        $latest = $params ? $wpdb->get_var($wpdb->prepare($sql, ...$params)) : $wpdb->get_var($sql);

        return $latest ? (string) $latest : null;
    }

    /**
     * @return array<int,array{date:string,scans:int}>
     */
    public function scanCountsByDay(DateRange $range): array {
        global $wpdb;

        $days = $range->daysForTrend();
        $startTimestamp = strtotime('-' . ($days - 1) . ' days', current_time('timestamp'));
        $start = gmdate('Y-m-d 00:00:00', $startTimestamp);
        $end = gmdate('Y-m-d H:i:s', current_time('timestamp'));
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                'SELECT DATE(scanned_at) AS scan_date, COUNT(*) AS scan_count FROM ' . Schema::scansTable() . ' WHERE scanned_at BETWEEN %s AND %s GROUP BY DATE(scanned_at) ORDER BY scan_date ASC',
                $start,
                $end
            )
        );
        $counts = [];

        foreach ($rows ?: [] as $row) {
            $counts[(string) $row->scan_date] = (int) $row->scan_count;
        }

        $series = [];
        for ($i = 0; $i < $days; $i++) {
            $date = gmdate('Y-m-d', strtotime('+' . $i . ' days', $startTimestamp));
            $series[] = [
                'date' => $date,
                'scans' => $counts[$date] ?? 0,
            ];
        }

        return $series;
    }

    /**
     * @return object[]
     */
    public function topQrCodes(DateRange $range, int $limit = 10): array {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $where = $this->dateWhere($range, 's');
        $params = $this->dateParams($range);
        $params[] = $limit;

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT q.id, q.name, q.destination_url, q.shortcode, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan
                 FROM {$qrcodesTable} q
                 INNER JOIN {$scansTable} s ON s.qr_id = q.id
                 {$where}
                 GROUP BY q.id, q.name, q.destination_url, q.shortcode
                 ORDER BY scan_count DESC, last_scan DESC
                 LIMIT %d",
                ...$params
            )
        ) ?: [];
    }

    /**
     * @return object[]
     */
    public function recentScans(DateRange $range, int $limit = 20): array {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $where = $this->dateWhere($range, 's');
        $params = $this->dateParams($range);
        $params[] = $limit;
        $rows = $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, q.name AS qr_name, q.shortcode
                 FROM {$scansTable} s
                 INNER JOIN {$qrcodesTable} q ON q.id = s.qr_id
                 {$where}
                 ORDER BY s.scanned_at DESC
                 LIMIT %d",
                ...$params
            )
        ) ?: [];

        foreach ($rows as $row) {
            $row->user_agent_summary = $this->parser->summary((string) $row->user_agent);
        }

        return $rows;
    }

    /**
     * @return array<int,array{label:string,count:int}>
     */
    public function deviceBreakdown(DateRange $range): array {
        return $this->breakdown($range, 'deviceType');
    }

    /**
     * @return array<int,array{label:string,count:int}>
     */
    public function browserBreakdown(DateRange $range): array {
        return $this->breakdown($range, 'browserFamily');
    }

    public function mostScanned(DateRange $range): ?object {
        $top = $this->topQrCodes($range, 1);

        return $top[0] ?? null;
    }

    /**
     * @return array{total_qr_codes:int,total_scans:int,scans_today:int,scans_last_7_days:int,most_scanned:?object,latest_scan:?string}
     */
    public function dashboardStats(DateRange $range): array {
        return [
            'total_qr_codes' => $this->totalQrCodes(),
            'total_scans' => $this->totalScans($range),
            'scans_today' => $this->scansToday(),
            'scans_last_7_days' => $this->scansLastSevenDays(),
            'most_scanned' => $this->mostScanned($range),
            'latest_scan' => $this->latestScan($range),
        ];
    }

    private function dateWhere(?DateRange $range, string $alias = ''): string {
        if (!$range || !$range->start || !$range->end) {
            return '';
        }

        $column = $alias !== '' ? $alias . '.scanned_at' : 'scanned_at';

        return ' WHERE ' . $column . ' BETWEEN %s AND %s';
    }

    private function dateParams(?DateRange $range): array {
        if (!$range || !$range->start || !$range->end) {
            return [];
        }

        return [$range->start, $range->end];
    }

    private function breakdown(DateRange $range, string $parserMethod): array {
        global $wpdb;

        $where = $this->dateWhere($range);
        $params = $this->dateParams($range);
        $sql = 'SELECT user_agent FROM ' . Schema::scansTable() . $where;
        $rows = $params ? $wpdb->get_results($wpdb->prepare($sql, ...$params)) : $wpdb->get_results($sql);
        $counts = [];

        foreach ($rows ?: [] as $row) {
            $label = $this->parser->{$parserMethod}((string) $row->user_agent);
            $counts[$label] = ($counts[$label] ?? 0) + 1;
        }

        arsort($counts);

        return array_map(
            static fn(string $label, int $count): array => ['label' => $label, 'count' => $count],
            array_keys($counts),
            array_values($counts)
        );
    }
}
