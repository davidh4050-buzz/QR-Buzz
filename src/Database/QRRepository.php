<?php
namespace QRBuzz\Database;

use QRBuzz\Models\QRCode;
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
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $rows = $wpdb->get_results(
            "SELECT q.*, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan
             FROM {$qrcodesTable} q
             LEFT JOIN {$scansTable} s ON s.qr_id = q.id
             GROUP BY q.id
             ORDER BY q.created_at DESC"
        );

        return array_map([QRCode::class, 'fromRow'], $rows ?: []);
    }

    public function find(int $id): ?QRCode {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT q.*, COUNT(s.id) AS scan_count, MAX(s.scanned_at) AS last_scan
                 FROM {$qrcodesTable} q
                 LEFT JOIN {$scansTable} s ON s.qr_id = q.id
                 WHERE q.id = %d
                 GROUP BY q.id",
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

    public function create(string $name, string $destinationUrl): int {
        global $wpdb;

        $now = current_time('mysql');
        $wpdb->insert(
            Schema::qrcodesTable(),
            [
                'name' => $name,
                'destination_url' => $destinationUrl,
                'shortcode' => $this->uniqueShortcode(),
                'created_at' => $now,
                'updated_at' => $now,
                'active' => 1,
            ],
            ['%s', '%s', '%s', '%s', '%s', '%d']
        );

        return (int) $wpdb->insert_id;
    }

    public function update(int $id, string $name, string $destinationUrl, bool $active): bool {
        global $wpdb;

        $result = $wpdb->update(
            Schema::qrcodesTable(),
            [
                'name' => $name,
                'destination_url' => $destinationUrl,
                'updated_at' => current_time('mysql'),
                'active' => $active ? 1 : 0,
            ],
            ['id' => $id],
            ['%s', '%s', '%s', '%d'],
            ['%d']
        );

        return $result !== false;
    }

    public function delete(int $id): bool {
        global $wpdb;

        $wpdb->delete(Schema::scansTable(), ['qr_id' => $id], ['%d']);
        $result = $wpdb->delete(Schema::qrcodesTable(), ['id' => $id], ['%d']);

        return $result !== false;
    }

    public function logScan(QRCode $qrCode, array $server): void {
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
            ],
            ['%d', '%s', '%s', '%s', '%s', '%s']
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

    private function uniqueShortcode(): string {
        do {
            $shortcode = $this->shortcodes->generate();
        } while ($this->findByShortcode($shortcode) !== null);

        return $shortcode;
    }

    private function hashIp(string $ip): string {
        return hash_hmac('sha256', $ip, wp_salt('auth'));
    }
}
