<?php
namespace QRBuzz\Database;

use QRBuzz\Models\QRCode;
use QRBuzz\Redirect\Resolution;
use QRBuzz\Utils\ShortcodeGenerator;

class QRRepository {

    private ShortcodeGenerator $shortcodes;

    public function __construct(?ShortcodeGenerator $shortcodes = null) {
        $this->shortcodes = $shortcodes ?: new ShortcodeGenerator();
    }

    /**
     * @return QRCode[]
     */
    public function allWithScanCounts(): array {
        return $this->queryWithScanCounts();
    }

    /**
     * @return QRCode[]
     */
    public function queryWithScanCounts(string $search = '', int $page = 1, int $perPage = 20, string $orderby = 'created_at', string $order = 'DESC'): array {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $orderby = $this->allowedOrderby($orderby);
        $order = strtoupper($order) === 'ASC' ? 'ASC' : 'DESC';
        $offset = max(0, ($page - 1) * $perPage);
        $where = '';
        $params = [];

        if ($search !== '') {
            $where = 'WHERE q.name LIKE %s';
            $params[] = '%' . $wpdb->esc_like($search) . '%';
        }

        $sql = "SELECT q.*, COALESCE(stats.scan_count, 0) AS scan_count, stats.last_scan
             FROM {$qrcodesTable} q
             LEFT JOIN (
                SELECT qr_id, COUNT(id) AS scan_count, MAX(scanned_at) AS last_scan
                FROM {$scansTable}
                GROUP BY qr_id
             ) stats ON stats.qr_id = q.id
             {$where}
             ORDER BY {$orderby} {$order}
             LIMIT %d OFFSET %d";

        $params[] = $perPage;
        $params[] = $offset;
        $rows = $wpdb->get_results($wpdb->prepare($sql, ...$params));

        return array_map([QRCode::class, 'fromRow'], $rows ?: []);
    }

    public function count(string $search = ''): int {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();

        if ($search === '') {
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$qrcodesTable}");
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT COUNT(*) FROM {$qrcodesTable} WHERE name LIKE %s",
                '%' . $wpdb->esc_like($search) . '%'
            )
        );
    }

    public function find(int $id): ?QRCode {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT q.*, COALESCE(stats.scan_count, 0) AS scan_count, stats.last_scan
                 FROM {$qrcodesTable} q
                 LEFT JOIN (
                    SELECT qr_id, COUNT(id) AS scan_count, MAX(scanned_at) AS last_scan
                    FROM {$scansTable}
                    GROUP BY qr_id
                 ) stats ON stats.qr_id = q.id
                 WHERE q.id = %d
                 LIMIT 1",
                $id
            )
        );

        return $row ? QRCode::fromRow($row) : null;
    }

    public function findByShortcode(string $shortcode): ?QRCode {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT * FROM {$qrcodesTable} WHERE shortcode = %s LIMIT 1",
                $shortcode
            )
        );

        return $row ? QRCode::fromRow($row) : null;
    }

    public function create(string $name, string $destinationUrl, array $settings = []): int {
        global $wpdb;

        $now = current_time('mysql');
        $status = $this->normalizeStatus($settings['status'] ?? 'active');
        $wpdb->insert(
            Schema::qrcodesTable(),
            [
                'name' => $name,
                'destination_url' => $destinationUrl,
                'shortcode' => $this->uniqueShortcode(),
                'created_at' => $now,
                'updated_at' => $now,
                'active' => $status === 'active' ? 1 : 0,
                'status' => $status,
                'fallback_url' => $this->nullableUrl($settings['fallback_url'] ?? null),
                'expires_at' => $this->nullableString($settings['expires_at'] ?? null),
                'scheduled_url' => $this->nullableUrl($settings['scheduled_url'] ?? null),
                'scheduled_start_at' => $this->nullableString($settings['scheduled_start_at'] ?? null),
                'scheduled_end_at' => $this->nullableString($settings['scheduled_end_at'] ?? null),
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s']
        );

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, string $name, string $destinationUrl, string $status, array $settings = [], int $userId = 0): bool {
        global $wpdb;

        $before = $this->find($id);
        $status = $this->normalizeStatus($status);
        $data = [
            'name' => $name,
            'destination_url' => $destinationUrl,
            'updated_at' => current_time('mysql'),
            'active' => $status === 'active' ? 1 : 0,
            'status' => $status,
            'fallback_url' => $this->nullableUrl($settings['fallback_url'] ?? null),
            'expires_at' => $this->nullableString($settings['expires_at'] ?? null),
            'scheduled_url' => $this->nullableUrl($settings['scheduled_url'] ?? null),
            'scheduled_start_at' => $this->nullableString($settings['scheduled_start_at'] ?? null),
            'scheduled_end_at' => $this->nullableString($settings['scheduled_end_at'] ?? null),
        ];
        $result = $wpdb->update(
            Schema::qrcodesTable(),
            $data,
            ['id' => $id],
            ['%s', '%s', '%s', '%d', '%s', '%s', '%s', '%s', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            return false;
        }

        if ($before) {
            $this->recordDestinationChanges($before, $data, $userId);
        }

        return true;
    }

    public function setActive(int $id, bool $active): bool {
        global $wpdb;

        $before = $this->find($id);
        $status = $active ? 'active' : 'paused';
        $result = $wpdb->update(
            Schema::qrcodesTable(),
            [
                'active' => $active ? 1 : 0,
                'status' => $status,
                'updated_at' => current_time('mysql'),
            ],
            ['id' => $id],
            ['%d', '%s', '%s'],
            ['%d']
        );

        if ($result === false) {
            return false;
        }

        if ($before && $before->status !== $status) {
            $this->recordHistory($id, 'status_changed', $before->status, $status, get_current_user_id());
        }

        return true;
    }

    public function delete(int $id): bool {
        global $wpdb;

        $wpdb->delete(Schema::scansTable(), ['qr_id' => $id], ['%d']);
        $wpdb->delete(Schema::destinationHistoryTable(), ['qr_id' => $id], ['%d']);
        $result = $wpdb->delete(Schema::qrcodesTable(), ['id' => $id], ['%d']);

        return $result !== false;
    }

    public function logScan(QRCode $qrCode, array $server, ?Resolution $resolution = null): void {
        global $wpdb;

        $ip = $server['REMOTE_ADDR'] ?? '';
        $userAgent = $server['HTTP_USER_AGENT'] ?? '';
        $referrer = $server['HTTP_REFERER'] ?? '';

        $wpdb->insert(
            Schema::scansTable(),
            [
                'qr_id' => $qrCode->id,
                'scanned_at' => current_time('mysql'),
                'ip_hash' => $this->hashIp((string) $ip),
                'user_agent' => sanitize_textarea_field((string) $userAgent),
                'referrer' => esc_url_raw((string) $referrer),
                'country' => null,
                'destination_resolved' => $resolution ? $resolution->destinationUrl : null,
                'resolution_reason' => $resolution ? $resolution->reason : null,
                'scan_status' => $resolution ? $resolution->scanStatus : null,
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s']
        );
    }

    public function scanCount(int $qrId): int {
        global $wpdb;

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                'SELECT COUNT(*) FROM ' . Schema::scansTable() . ' WHERE qr_id = %d',
                $qrId
            )
        );
    }

    public function lastScan(int $qrId): ?string {
        global $wpdb;

        $lastScan = $wpdb->get_var(
            $wpdb->prepare(
                'SELECT MAX(scanned_at) FROM ' . Schema::scansTable() . ' WHERE qr_id = %d',
                $qrId
            )
        );

        return $lastScan ? (string) $lastScan : null;
    }

    /**
     * @return object[]
     */
    public function recentScans(int $limit = 10): array {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();

        return $wpdb->get_results(
            $wpdb->prepare(
                "SELECT s.*, q.name AS qr_name, q.shortcode
                 FROM {$scansTable} s
                 INNER JOIN {$qrcodesTable} q ON q.id = s.qr_id
                 ORDER BY s.scanned_at DESC
                 LIMIT %d",
                $limit
            )
        ) ?: [];
    }

    /**
     * @return object[]
     */
    public function destinationHistory(int $qrId, int $limit = 10): array {
        global $wpdb;

        return $wpdb->get_results(
            $wpdb->prepare(
                'SELECT h.*, u.display_name AS user_name FROM ' . Schema::destinationHistoryTable() . ' h LEFT JOIN ' . $wpdb->users . ' u ON u.ID = h.changed_by WHERE h.qr_id = %d ORDER BY h.changed_at DESC LIMIT %d',
                $qrId,
                $limit
            )
        ) ?: [];
    }

    /**
     * @return QRCode[]
     */
    public function recentQrCodes(int $limit = 5): array {
        return $this->queryWithScanCounts('', 1, $limit, 'created_at', 'DESC');
    }

    public function totalScans(): int {
        global $wpdb;

        return (int) $wpdb->get_var('SELECT COUNT(*) FROM ' . Schema::scansTable());
    }

    public function latestScan(): ?string {
        global $wpdb;

        $latest = $wpdb->get_var('SELECT MAX(scanned_at) FROM ' . Schema::scansTable());

        return $latest ? (string) $latest : null;
    }

    public function mostScanned(): ?QRCode {
        $results = $this->queryWithScanCounts('', 1, 1, 'scan_count', 'DESC');

        return $results[0] ?? null;
    }

    /**
     * @return array{total_qr_codes:int,total_scans:int,most_scanned:?QRCode,latest_scan:?string,recent_qr_codes:QRCode[]}
     */
    public function dashboardSummary(): array {
        return [
            'total_qr_codes' => $this->count(),
            'total_scans' => $this->totalScans(),
            'most_scanned' => $this->mostScanned(),
            'latest_scan' => $this->latestScan(),
            'recent_qr_codes' => $this->recentQrCodes(),
        ];
    }

    private function recordDestinationChanges(QRCode $before, array $after, int $userId): void {
        $changes = [
            'destination_url' => ['primary_destination_updated', $before->destinationUrl],
            'scheduled_url' => ['scheduled_destination_updated', $before->scheduledUrl],
            'scheduled_start_at' => ['scheduled_destination_updated', $before->scheduledStartAt],
            'scheduled_end_at' => ['scheduled_destination_updated', $before->scheduledEndAt],
            'fallback_url' => ['fallback_updated', $before->fallbackUrl],
            'status' => ['status_changed', $before->status],
            'expires_at' => ['expiry_updated', $before->expiresAt],
        ];

        foreach ($changes as $field => [$changeType, $previous]) {
            $new = $after[$field] ?? null;
            if ((string) $previous === (string) $new) {
                continue;
            }

            $this->recordHistory($before->id, $changeType, $previous, $new, $userId);
        }
    }

    private function recordHistory(int $qrId, string $changeType, $previous, $new, int $userId): void {
        global $wpdb;

        $wpdb->insert(
            Schema::destinationHistoryTable(),
            [
                'qr_id' => $qrId,
                'change_type' => $changeType,
                'previous_value' => $previous === null ? null : (string) $previous,
                'new_value' => $new === null ? null : (string) $new,
                'changed_by' => $userId > 0 ? $userId : null,
                'changed_at' => current_time('mysql'),
            ],
            ['%d', '%s', '%s', '%s', '%d', '%s']
        );
    }

    private function uniqueShortcode(): string {
        do {
            $shortcode = $this->shortcodes->generate();
        } while ($this->findByShortcode($shortcode) !== null);

        return $shortcode;
    }

    private function hashIp(string $ip): string {
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }

    private function normalizeStatus(string $status): string {
        return in_array($status, ['active', 'paused'], true) ? $status : 'active';
    }

    private function nullableUrl($url): ?string {
        $url = trim((string) $url);

        return $url === '' ? null : esc_url_raw($url);
    }

    private function nullableString($value): ?string {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function allowedOrderby(string $orderby): string {
        $allowed = [
            'name' => 'q.name',
            'created_at' => 'q.created_at',
            'active' => 'q.active',
            'scan_count' => 'scan_count',
        ];

        return $allowed[$orderby] ?? $allowed['created_at'];
    }
}
