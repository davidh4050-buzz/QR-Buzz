<?php
namespace QRBuzz\Analytics;

use QRBuzz\Database\Schema;
use QRBuzz\Membership\EntitlementService;
use QRBuzz\Platform\PlatformEventRepository;
use QRBuzz\Workspace\WorkspaceService;

class AnalyticsCsvExporter {

    private EntitlementService $entitlements;
    private WorkspaceService $workspaces;
    private UserAgentParser $parser;
    private PlatformEventRepository $events;

    public function __construct(?EntitlementService $entitlements = null, ?WorkspaceService $workspaces = null, ?UserAgentParser $parser = null, ?PlatformEventRepository $events = null) {
        $this->workspaces = $workspaces ?: new WorkspaceService();
        $this->entitlements = $entitlements ?: new EntitlementService($this->workspaces);
        $this->parser = $parser ?: new UserAgentParser();
        $this->events = $events ?: new PlatformEventRepository();
    }

    public function export(string $scope, int $entityId, string $rangeKey): void {
        if (!is_user_logged_in()) { auth_redirect(); }
        check_admin_referer('qrbuzz_analytics_export');
        if (!$this->entitlements->allows('csv_export')) {
            wp_safe_redirect(home_url('/app/analytics?upgrade=csv_export'));
            exit;
        }
        $scope = in_array($scope, ['workspace', 'qr', 'campaign'], true) ? $scope : 'workspace';
        $workspaceId = $this->workspaces->id();
        $entity = $this->validateEntity($scope, $entityId, $workspaceId);
        if ($scope !== 'workspace' && !$entity) { wp_die(esc_html__('Analytics export was not found in this workspace.', 'qr-buzz'), 404); }
        $range = $this->boundedRange(DateRange::fromRequest($rangeKey));
        $filename = $this->filename($scope, $entity, $range);
        $this->events->record('analytics_exported', ['user_id' => get_current_user_id(), 'workspace_id' => $workspaceId, 'metadata' => ['scope' => $scope, 'entity_id' => $entityId, 'range' => $range->key]]);

        // Build the export completely before committing HTTP headers. Some managed
        // WordPress hosts terminate long chunked responses, which browsers report as
        // ERR_INVALID_RESPONSE. A temporary file gives the response an exact length.
        $tempFile = wp_tempnam($filename);
        if (!$tempFile) { wp_die(esc_html__('QR Buzz could not create the CSV download file.', 'qr-buzz')); }
        $out = fopen($tempFile, 'wb');
        if ($out === false) {
            @unlink($tempFile);
            wp_die(esc_html__('QR Buzz could not open the CSV download file.', 'qr-buzz'));
        }
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, ['Timestamp', 'QR Name', 'QR ID', 'Campaign', 'QR Type', 'Destination', 'Device', 'Browser', 'Referrer / Source', 'Country', 'Resolved Destination', 'Resolution Reason', 'Scan Status']);

        global $wpdb;
        $offset = 0; $batchSize = 1000;
        do {
            [$where, $params] = $this->where($scope, $entityId, $workspaceId, $range);
            $sql = 'SELECT s.scanned_at, s.user_agent, s.referrer, s.country, s.destination_resolved, s.resolution_reason, s.scan_status, q.id AS qr_id, q.name AS qr_name, q.type AS qr_type, q.destination_url, c.name AS campaign_name FROM ' . Schema::scansTable() . ' s INNER JOIN ' . Schema::qrcodesTable() . ' q ON q.id = s.qr_id LEFT JOIN ' . Schema::campaignsTable() . ' c ON c.id = q.campaign_id ' . $where . ' ORDER BY s.id ASC LIMIT %d OFFSET %d';
            $params[] = $batchSize; $params[] = $offset;
            $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));
            foreach ($rows ?: [] as $row) {
                $userAgent = (string) $row->user_agent;
                $values = [
                    gmdate('Y-m-d\TH:i:s\Z', strtotime((string) $row->scanned_at)),
                    $row->qr_name,
                    $row->qr_id,
                    $row->campaign_name ?: '',
                    $row->qr_type,
                    $row->destination_url,
                    $this->parser->deviceType($userAgent),
                    $this->parser->browserFamily($userAgent),
                    $row->referrer ?: '',
                    $row->country ?: '',
                    $row->destination_resolved ?: '',
                    $row->resolution_reason ?: '',
                    $row->scan_status ?: '',
                ];
                fputcsv($out, array_map([$this, 'safeCell'], $values));
            }
            $count = count($rows ?: []);
            $offset += $batchSize;
        } while ($count === $batchSize);

        fclose($out);
        $fileSize = filesize($tempFile);
        if ($fileSize === false) {
            @unlink($tempFile);
            wp_die(esc_html__('QR Buzz could not finalise the CSV download.', 'qr-buzz'));
        }

        while (ob_get_level() > 0) { ob_end_clean(); }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        header('Content-Length: ' . (string) $fileSize);
        header('X-Content-Type-Options: nosniff');
        readfile($tempFile);
        @unlink($tempFile);
        exit;
    }

    private function validateEntity(string $scope, int $entityId, int $workspaceId): ?object {
        if ($scope === 'workspace') { return (object) ['name' => 'workspace']; }
        global $wpdb;
        $table = $scope === 'qr' ? Schema::qrcodesTable() : Schema::campaignsTable();
        return $wpdb->get_row($wpdb->prepare('SELECT id, name FROM ' . $table . ' WHERE id = %d AND workspace_id = %d LIMIT 1', $entityId, $workspaceId));
    }

    private function boundedRange(DateRange $range): DateRange {
        $days = $this->entitlements->limit('analytics_retention_days');
        if ($days === null) { return $range; }
        $minimum = gmdate('Y-m-d 00:00:00', strtotime('-' . max(0, $days - 1) . ' days', current_time('timestamp')));
        if ($range->start === null || strtotime($range->start) < strtotime($minimum)) {
            return new DateRange($range->key, $range->label, $minimum, $range->end ?: gmdate('Y-m-d H:i:s', current_time('timestamp')));
        }
        return $range;
    }

    private function where(string $scope, int $entityId, int $workspaceId, DateRange $range): array {
        $where = 'WHERE q.workspace_id = %d';
        $params = [$workspaceId];
        if ($scope === 'qr') { $where .= ' AND q.id = %d'; $params[] = $entityId; }
        if ($scope === 'campaign') { $where .= ' AND q.campaign_id = %d'; $params[] = $entityId; }
        if ($range->start !== null) { $where .= ' AND s.scanned_at >= %s'; $params[] = $range->start; }
        if ($range->end !== null) { $where .= ' AND s.scanned_at <= %s'; $params[] = $range->end; }
        return [$where, $params];
    }

    private function filename(string $scope, object $entity, DateRange $range): string {
        $subject = $scope === 'workspace' ? 'workspace' : sanitize_title((string) $entity->name);
        $from = $range->start ? gmdate('Y-m-d', strtotime($range->start)) : 'all-time';
        $to = $range->end ? gmdate('Y-m-d', strtotime($range->end)) : gmdate('Y-m-d');
        return 'qr-buzz-' . $scope . '-analytics-' . $subject . '-' . $from . '-to-' . $to . '.csv';
    }

    public function safeCell($value): string {
        $value = (string) $value;
        return $value !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/', $value) ? "'" . $value : $value;
    }
}
