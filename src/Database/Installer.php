<?php
namespace QRBuzz\Database;

class Installer {

    public static function activate(): void {
        self::createTables();
        update_option('qrbuzz_db_version', QR_BUZZ_VERSION);
    }

    public static function createTables(): void {
        global $wpdb;

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $charsetCollate = $wpdb->get_charset_collate();
        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $historyTable = Schema::destinationHistoryTable();

        $qrcodesSql = "CREATE TABLE {$qrcodesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            destination_url text NOT NULL,
            shortcode varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            status varchar(20) NOT NULL DEFAULT 'active',
            fallback_url text NULL,
            expires_at datetime NULL,
            scheduled_url text NULL,
            scheduled_start_at datetime NULL,
            scheduled_end_at datetime NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY shortcode (shortcode),
            KEY active (active),
            KEY status (status),
            KEY expires_at (expires_at)
        ) {$charsetCollate};";

        $scansSql = "CREATE TABLE {$scansTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            qr_id bigint(20) unsigned NOT NULL,
            scanned_at datetime NOT NULL,
            ip_hash char(64) NOT NULL,
            user_agent text NULL,
            referrer text NULL,
            country varchar(2) NULL,
            destination_resolved text NULL,
            resolution_reason varchar(32) NULL,
            scan_status varchar(32) NULL,
            PRIMARY KEY  (id),
            KEY qr_id (qr_id),
            KEY scanned_at (scanned_at),
            KEY scan_status (scan_status),
            KEY resolution_reason (resolution_reason)
        ) {$charsetCollate};";

        $historySql = "CREATE TABLE {$historyTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            qr_id bigint(20) unsigned NOT NULL,
            change_type varchar(64) NOT NULL,
            previous_value text NULL,
            new_value text NULL,
            changed_by bigint(20) unsigned NULL,
            changed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY qr_id (qr_id),
            KEY change_type (change_type),
            KEY changed_at (changed_at)
        ) {$charsetCollate};";

        dbDelta($qrcodesSql);
        dbDelta($scansSql);
        dbDelta($historySql);
        self::backfillStatuses();
    }

    private static function backfillStatuses(): void {
        global $wpdb;

        $qrcodesTable = Schema::qrcodesTable();
        $wpdb->query("UPDATE {$qrcodesTable} SET status = 'active' WHERE status = '' OR status IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET status = 'paused' WHERE active = 0 AND status = 'active'");
    }
}
