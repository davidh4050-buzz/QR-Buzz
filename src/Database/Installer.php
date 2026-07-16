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
        $workspacesTable = Schema::workspacesTable();
        $qrcodesTable = Schema::qrcodesTable();
        $scansTable = Schema::scansTable();
        $historyTable = Schema::destinationHistoryTable();
        $campaignsTable = Schema::campaignsTable();
        $rulesTable = Schema::destinationRulesTable();

        $workspacesSql = "CREATE TABLE {$workspacesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            owner_user_id bigint(20) unsigned NULL,
            plan_key varchar(32) NOT NULL DEFAULT 'free',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY plan_key (plan_key)
        ) {$charsetCollate};";

        $qrcodesSql = "CREATE TABLE {$qrcodesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NULL,
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
            type varchar(32) NOT NULL DEFAULT 'dynamic_url',
            payload_data longtext NULL,
            static_payload longtext NULL,
            is_trackable tinyint(1) NOT NULL DEFAULT 1,
            campaign_id bigint(20) unsigned NULL,
            theme varchar(32) NOT NULL DEFAULT 'classic',
            foreground_color varchar(7) NOT NULL DEFAULT '#000000',
            background_color varchar(7) NOT NULL DEFAULT '#ffffff',
            transparent_background tinyint(1) NOT NULL DEFAULT 0,
            error_correction varchar(1) NOT NULL DEFAULT 'H',
            margin int(11) NOT NULL DEFAULT 12,
            logo_attachment_id bigint(20) unsigned NULL,
            logo_size int(11) NOT NULL DEFAULT 20,
            PRIMARY KEY  (id),
            UNIQUE KEY shortcode (shortcode),
            KEY workspace_id (workspace_id),
            KEY active (active),
            KEY status (status),
            KEY expires_at (expires_at),
            KEY type (type),
            KEY is_trackable (is_trackable),
            KEY campaign_id (campaign_id),
            KEY theme (theme)
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
            KEY qr_scanned_at (qr_id, scanned_at),
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

        $campaignsSql = "CREATE TABLE {$campaignsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NULL,
            name varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            description text NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY slug (slug),
            KEY status (status)
        ) {$charsetCollate};";

        $rulesSql = "CREATE TABLE {$rulesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NULL,
            qr_id bigint(20) unsigned NOT NULL,
            name varchar(191) NOT NULL,
            priority int(11) NOT NULL DEFAULT 10,
            status varchar(20) NOT NULL DEFAULT 'active',
            destination_url text NOT NULL,
            conditions_json longtext NULL,
            starts_at datetime NULL,
            ends_at datetime NULL,
            days_of_week varchar(32) NULL,
            time_start varchar(5) NULL,
            time_end varchar(5) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY qr_id (qr_id),
            KEY priority (priority),
            KEY status (status),
            KEY starts_at (starts_at),
            KEY ends_at (ends_at)
        ) {$charsetCollate};";

        dbDelta($workspacesSql);
        dbDelta($qrcodesSql);
        dbDelta($scansSql);
        dbDelta($historySql);
        dbDelta($campaignsSql);
        dbDelta($rulesSql);
        $workspaceId = self::ensureDefaultWorkspace();
        self::backfillWorkspaceIds($workspaceId);
        self::backfillStatuses();
        self::backfillTypes();
        self::backfillCampaigns();
        self::backfillDesignSettings();
        (new DestinationRuleRepository())->migrateLegacyScheduledDestinations();
        self::backfillWorkspaceIds($workspaceId);
    }

    public static function ensureDefaultWorkspace(): int {
        return (new WorkspaceRepository())->createDefault();
    }

    private static function backfillWorkspaceIds(int $workspaceId): void {
        global $wpdb;
        $workspaceId = absint($workspaceId);
        if ($workspaceId <= 0) {
            return;
        }

        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::qrcodesTable() . ' SET workspace_id = %d WHERE workspace_id IS NULL OR workspace_id = 0', $workspaceId));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::campaignsTable() . ' SET workspace_id = %d WHERE workspace_id IS NULL OR workspace_id = 0', $workspaceId));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::destinationRulesTable() . ' SET workspace_id = %d WHERE workspace_id IS NULL OR workspace_id = 0', $workspaceId));
    }

    private static function backfillStatuses(): void {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $wpdb->query("UPDATE {$qrcodesTable} SET status = 'active' WHERE status = '' OR status IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET status = 'paused' WHERE active = 0 AND status = 'active'");
    }

    private static function backfillTypes(): void {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $wpdb->query("UPDATE {$qrcodesTable} SET type = 'dynamic_url' WHERE type = '' OR type IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET is_trackable = 1 WHERE type = 'dynamic_url'");
        $wpdb->query("UPDATE {$qrcodesTable} SET static_payload = destination_url WHERE type = 'dynamic_url' AND (static_payload IS NULL OR static_payload = '')");
    }

    private static function backfillCampaigns(): void {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $wpdb->query("UPDATE {$qrcodesTable} SET campaign_id = NULL WHERE campaign_id = 0");
    }

    private static function backfillDesignSettings(): void {
        global $wpdb;
        $qrcodesTable = Schema::qrcodesTable();
        $wpdb->query("UPDATE {$qrcodesTable} SET theme = 'classic' WHERE theme = '' OR theme IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET foreground_color = '#000000' WHERE foreground_color = '' OR foreground_color IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET background_color = '#ffffff' WHERE background_color = '' OR background_color IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET error_correction = 'H' WHERE error_correction = '' OR error_correction IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET margin = 12 WHERE margin IS NULL");
        $wpdb->query("UPDATE {$qrcodesTable} SET logo_size = 20 WHERE logo_size IS NULL OR logo_size = 0");
        $wpdb->query("UPDATE {$qrcodesTable} SET logo_attachment_id = NULL WHERE logo_attachment_id = 0");
    }
}
