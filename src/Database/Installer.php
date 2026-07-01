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

        $qrcodesSql = "CREATE TABLE {$qrcodesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            destination_url text NOT NULL,
            shortcode varchar(32) NOT NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            active tinyint(1) NOT NULL DEFAULT 1,
            PRIMARY KEY  (id),
            UNIQUE KEY shortcode (shortcode),
            KEY active (active)
        ) {$charsetCollate};";

        $scansSql = "CREATE TABLE {$scansTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            qr_id bigint(20) unsigned NOT NULL,
            scanned_at datetime NOT NULL,
            ip_hash char(64) NOT NULL,
            user_agent text NULL,
            referrer text NULL,
            country varchar(2) NULL,
            PRIMARY KEY  (id),
            KEY qr_id (qr_id),
            KEY scanned_at (scanned_at)
        ) {$charsetCollate};";

        dbDelta($qrcodesSql);
        dbDelta($scansSql);
    }
}
