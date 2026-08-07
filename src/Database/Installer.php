<?php
namespace QRBuzz\Database;

class Installer {
    public const DESIGN_SCHEMA_VERSION = '2026-08-07-caption-font-brand-kit';

    public static function activate(): void {
        self::createTables();
        self::registerRoles();
        update_option('qrbuzz_db_version', QR_BUZZ_VERSION);
        update_option('qrbuzz_design_schema_version', self::DESIGN_SCHEMA_VERSION);
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
        $membersTable = Schema::membershipsTable();
        $profilesTable = Schema::profilesTable();
        $subscriptionsTable = Schema::subscriptionsTable();
        $webhookEventsTable = Schema::webhookEventsTable();
        $auditEventsTable = Schema::auditEventsTable();
        $platformEventsTable = Schema::platformEventsTable();
        $applicationErrorsTable = Schema::applicationErrorsTable();
        $webhookLogsTable = Schema::webhookLogsTable();
        $featureFlagsTable = Schema::featureFlagsTable();
        $diagnosticSnapshotsTable = Schema::diagnosticSnapshotsTable();

        dbDelta("CREATE TABLE {$workspacesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            name varchar(191) NOT NULL,
            slug varchar(191) NOT NULL,
            status varchar(20) NOT NULL DEFAULT 'active',
            owner_user_id bigint(20) unsigned NULL,
            plan_key varchar(32) NOT NULL DEFAULT 'free',
            onboarding_status varchar(32) NOT NULL DEFAULT 'complete',
            onboarding_step varchar(32) NULL,
            timezone varchar(64) NULL,
            website_url text NULL,
            intended_use varchar(64) NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY slug (slug),
            KEY status (status),
            KEY owner_user_id (owner_user_id),
            KEY plan_key (plan_key),
            KEY onboarding_status (onboarding_status)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$qrcodesTable} (
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
            dot_style varchar(20) NOT NULL DEFAULT 'square',
            finder_style varchar(20) NOT NULL DEFAULT 'square',
            finder_dot_style varchar(20) NOT NULL DEFAULT 'square',
            finder_color varchar(7) NULL,
            caption text NULL,
            caption_font_size int(11) NOT NULL DEFAULT 16,
            caption_font_color varchar(7) NOT NULL DEFAULT '#000000',
            caption_font_family varchar(32) NOT NULL DEFAULT 'arial',
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
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$scansTable} (
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
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$historyTable} (
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
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$campaignsTable} (
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
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$rulesTable} (
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
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$membersTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NOT NULL,
            role varchar(32) NOT NULL DEFAULT 'workspace_owner',
            status varchar(20) NOT NULL DEFAULT 'active',
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY workspace_user (workspace_id, user_id),
            KEY user_id (user_id),
            KEY role (role),
            KEY status (status)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$profilesTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            user_id bigint(20) unsigned NOT NULL,
            email_verified tinyint(1) NOT NULL DEFAULT 0,
            verification_token_hash char(64) NULL,
            verification_sent_at datetime NULL,
            onboarding_status varchar(32) NOT NULL DEFAULT 'pending',
            onboarding_step varchar(32) NULL,
            terms_accepted_at datetime NULL,
            last_login_at datetime NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY user_id (user_id),
            KEY email_verified (email_verified),
            KEY onboarding_status (onboarding_status)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$subscriptionsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NOT NULL,
            user_id bigint(20) unsigned NULL,
            plan_key varchar(32) NOT NULL DEFAULT 'free',
            status varchar(32) NOT NULL DEFAULT 'free',
            stripe_customer_id varchar(191) NULL,
            stripe_subscription_id varchar(191) NULL,
            stripe_checkout_session_id varchar(191) NULL,
            current_period_start datetime NULL,
            current_period_end datetime NULL,
            cancel_at_period_end tinyint(1) NOT NULL DEFAULT 0,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY plan_key (plan_key),
            KEY status (status),
            KEY stripe_customer_id (stripe_customer_id),
            KEY stripe_subscription_id (stripe_subscription_id)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$webhookEventsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL DEFAULT 'stripe',
            event_id varchar(191) NOT NULL,
            event_type varchar(191) NOT NULL,
            processed_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_event (provider, event_id),
            KEY event_type (event_type)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$auditEventsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_type varchar(96) NOT NULL,
            actor_id bigint(20) unsigned NULL,
            actor_type varchar(32) NOT NULL DEFAULT 'system',
            workspace_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NULL,
            entity_type varchar(64) NULL,
            entity_id bigint(20) unsigned NULL,
            description text NULL,
            before_data longtext NULL,
            after_data longtext NULL,
            metadata longtext NULL,
            correlation_id varchar(64) NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_type (event_type),
            KEY actor_id (actor_id),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY entity (entity_type, entity_id),
            KEY created_at (created_at)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$platformEventsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            event_name varchar(96) NOT NULL,
            user_id bigint(20) unsigned NULL,
            workspace_id bigint(20) unsigned NULL,
            object_type varchar(64) NULL,
            object_id bigint(20) unsigned NULL,
            metadata longtext NULL,
            correlation_id varchar(64) NULL,
            occurred_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY event_name (event_name),
            KEY user_id (user_id),
            KEY workspace_id (workspace_id),
            KEY object (object_type, object_id),
            KEY occurred_at (occurred_at)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$applicationErrorsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            fingerprint char(64) NOT NULL,
            severity varchar(20) NOT NULL DEFAULT 'warning',
            category varchar(64) NOT NULL DEFAULT 'unknown',
            message_summary text NOT NULL,
            sanitized_context longtext NULL,
            workspace_id bigint(20) unsigned NULL,
            user_id bigint(20) unsigned NULL,
            qr_id bigint(20) unsigned NULL,
            campaign_id bigint(20) unsigned NULL,
            occurrence_count int(11) NOT NULL DEFAULT 1,
            status varchar(24) NOT NULL DEFAULT 'new',
            first_seen_at datetime NOT NULL,
            last_seen_at datetime NOT NULL,
            resolved_at datetime NULL,
            notes text NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY fingerprint (fingerprint),
            KEY severity (severity),
            KEY category (category),
            KEY status (status),
            KEY workspace_id (workspace_id),
            KEY user_id (user_id),
            KEY last_seen_at (last_seen_at)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$webhookLogsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            provider varchar(32) NOT NULL DEFAULT 'stripe',
            event_id varchar(191) NOT NULL,
            event_type varchar(191) NOT NULL,
            status varchar(32) NOT NULL DEFAULT 'received',
            workspace_id bigint(20) unsigned NULL,
            subscription_id bigint(20) unsigned NULL,
            received_at datetime NOT NULL,
            processed_at datetime NULL,
            retry_count int(11) NOT NULL DEFAULT 0,
            error_summary text NULL,
            metadata longtext NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY provider_event (provider, event_id),
            KEY event_type (event_type),
            KEY status (status),
            KEY workspace_id (workspace_id),
            KEY subscription_id (subscription_id),
            KEY received_at (received_at)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$featureFlagsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            flag_key varchar(96) NOT NULL,
            label varchar(191) NOT NULL,
            description text NULL,
            default_state tinyint(1) NOT NULL DEFAULT 0,
            current_state tinyint(1) NOT NULL DEFAULT 0,
            scope varchar(32) NOT NULL DEFAULT 'global',
            scope_id bigint(20) unsigned NULL,
            updated_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            updated_at datetime NOT NULL,
            PRIMARY KEY  (id),
            UNIQUE KEY flag_scope (flag_key, scope, scope_id),
            KEY flag_key (flag_key),
            KEY scope (scope),
            KEY updated_by (updated_by)
        ) {$charsetCollate};");

        dbDelta("CREATE TABLE {$diagnosticSnapshotsTable} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            workspace_id bigint(20) unsigned NULL,
            snapshot_type varchar(64) NOT NULL DEFAULT 'workspace',
            summary longtext NOT NULL,
            created_by bigint(20) unsigned NULL,
            created_at datetime NOT NULL,
            PRIMARY KEY  (id),
            KEY workspace_id (workspace_id),
            KEY snapshot_type (snapshot_type),
            KEY created_at (created_at)
        ) {$charsetCollate};");

        $workspaceId = self::ensureDefaultWorkspace();
        self::backfillWorkspaceIds($workspaceId);
        self::backfillStatuses();
        self::backfillTypes();
        self::backfillCampaigns();
        self::backfillDesignSettings();
        (new DestinationRuleRepository())->migrateLegacyScheduledDestinations();
        self::backfillWorkspaceIds($workspaceId);
        self::backfillMemberships($workspaceId);
        self::backfillSubscription($workspaceId);
        self::seedFeatureFlags();
    }

    public static function registerRoles(): void {
        add_role('qrbuzz_customer', 'QR Buzz Customer', ['read' => true]);
        $role = get_role('qrbuzz_customer');
        if ($role) {
            $role->add_cap('upload_files');
        }
        add_role('qrbuzz_platform_admin', 'QR Buzz Platform Admin', ['read' => true, 'qrbuzz_manage_platform' => true, 'upload_files' => true]);
        $platformRole = get_role('qrbuzz_platform_admin');
        if ($platformRole) { $platformRole->add_cap('qrbuzz_manage_platform'); }
        $administrator = get_role('administrator');
        if ($administrator) { $administrator->add_cap('qrbuzz_manage_platform'); }
    }

    public static function ensureDefaultWorkspace(): int { return (new WorkspaceRepository())->createDefault(); }

    private static function backfillWorkspaceIds(int $workspaceId): void {
        global $wpdb;
        if ($workspaceId <= 0) { return; }
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::qrcodesTable() . ' SET workspace_id = %d WHERE workspace_id IS NULL OR workspace_id = 0', $workspaceId));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::campaignsTable() . ' SET workspace_id = %d WHERE workspace_id IS NULL OR workspace_id = 0', $workspaceId));
        $wpdb->query($wpdb->prepare('UPDATE ' . Schema::destinationRulesTable() . ' SET workspace_id = %d WHERE workspace_id IS NULL OR workspace_id = 0', $workspaceId));
    }

    private static function backfillMemberships(int $workspaceId): void {
        global $wpdb;
        $workspace = (new WorkspaceRepository())->find($workspaceId);
        if (!$workspace || $workspace->ownerUserId <= 0) { return; }
        $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::membershipsTable() . ' WHERE workspace_id = %d AND user_id = %d', $workspaceId, $workspace->ownerUserId));
        if ($exists > 0) { return; }
        $now = current_time('mysql');
        $wpdb->insert(Schema::membershipsTable(), ['workspace_id' => $workspaceId, 'user_id' => $workspace->ownerUserId, 'role' => 'workspace_owner', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now], ['%d','%d','%s','%s','%s','%s']);
    }

    private static function backfillSubscription(int $workspaceId): void {
        global $wpdb;
        $workspace = (new WorkspaceRepository())->find($workspaceId);
        if (!$workspace) { return; }
        $exists = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::subscriptionsTable() . ' WHERE workspace_id = %d', $workspaceId));
        if ($exists > 0) { return; }
        $now = current_time('mysql');
        $wpdb->insert(Schema::subscriptionsTable(), ['workspace_id' => $workspaceId, 'user_id' => $workspace->ownerUserId ?: null, 'plan_key' => $workspace->planKey, 'status' => $workspace->planKey === 'free' ? 'free' : 'active', 'created_at' => $now, 'updated_at' => $now], ['%d','%d','%s','%s','%s','%s']);
    }

    private static function backfillStatuses(): void { global $wpdb; $table = Schema::qrcodesTable(); $wpdb->query("UPDATE {$table} SET status = 'active' WHERE status = '' OR status IS NULL"); $wpdb->query("UPDATE {$table} SET status = 'paused' WHERE active = 0 AND status = 'active'"); }
    private static function backfillTypes(): void { global $wpdb; $table = Schema::qrcodesTable(); $wpdb->query("UPDATE {$table} SET type = 'dynamic_url' WHERE type = '' OR type IS NULL"); $wpdb->query("UPDATE {$table} SET is_trackable = 1 WHERE type = 'dynamic_url'"); $wpdb->query("UPDATE {$table} SET static_payload = destination_url WHERE type = 'dynamic_url' AND (static_payload IS NULL OR static_payload = '')"); }
    private static function backfillCampaigns(): void { global $wpdb; $wpdb->query('UPDATE ' . Schema::qrcodesTable() . ' SET campaign_id = NULL WHERE campaign_id = 0'); }
    private static function backfillDesignSettings(): void { global $wpdb; $table = Schema::qrcodesTable(); $wpdb->query("UPDATE {$table} SET theme = 'classic' WHERE theme = '' OR theme IS NULL"); $wpdb->query("UPDATE {$table} SET foreground_color = '#000000' WHERE foreground_color = '' OR foreground_color IS NULL"); $wpdb->query("UPDATE {$table} SET background_color = '#ffffff' WHERE background_color = '' OR background_color IS NULL"); $wpdb->query("UPDATE {$table} SET error_correction = 'H' WHERE error_correction = '' OR error_correction IS NULL"); $wpdb->query("UPDATE {$table} SET margin = 12 WHERE margin IS NULL"); $wpdb->query("UPDATE {$table} SET logo_size = 20 WHERE logo_size IS NULL OR logo_size = 0"); $wpdb->query("UPDATE {$table} SET logo_attachment_id = NULL WHERE logo_attachment_id = 0"); $wpdb->query("UPDATE {$table} SET dot_style = 'square' WHERE dot_style = '' OR dot_style IS NULL"); $wpdb->query("UPDATE {$table} SET finder_style = 'square' WHERE finder_style = '' OR finder_style IS NULL"); $wpdb->query("UPDATE {$table} SET finder_dot_style = 'square' WHERE finder_dot_style = '' OR finder_dot_style IS NULL"); $wpdb->query("UPDATE {$table} SET caption_font_size = 16 WHERE caption_font_size IS NULL OR caption_font_size = 0"); $wpdb->query("UPDATE {$table} SET caption_font_color = '#000000' WHERE caption_font_color = '' OR caption_font_color IS NULL"); $wpdb->query("UPDATE {$table} SET caption_font_family = 'arial' WHERE caption_font_family = '' OR caption_font_family IS NULL"); }
    private static function seedFeatureFlags(): void { global $wpdb; $table = Schema::featureFlagsTable(); $now = current_time('mysql'); $flags = ['new_dashboard' => ['New dashboard', 'Enable the next dashboard layout experiments.'], 'library_grid_view' => ['Library grid view', 'Enable the customer Library card/grid view.'], 'compact_sidebar' => ['Compact sidebar', 'Enable a denser sidebar layout experiment.'], 'enhanced_onboarding' => ['Enhanced onboarding', 'Enable experimental onboarding improvements.'], 'qr_templates' => ['QR templates', 'Enable future QR template experiments.'], 'dark_mode' => ['Dark mode', 'Enable dark mode experiments.'], 'advanced_insights' => ['Advanced insights', 'Enable richer deterministic insight experiments.'], 'purpose_driven_creation' => ['Purpose-driven creation', 'Enable guided QR creation experiments.']]; foreach ($flags as $key => [$label, $description]) { $exists = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM {$table} WHERE flag_key = %s AND scope = 'global' AND (scope_id IS NULL OR scope_id = 0)", $key)); if ($exists > 0) { continue; } $wpdb->insert($table, ['flag_key' => $key, 'label' => $label, 'description' => $description, 'default_state' => 0, 'current_state' => 0, 'scope' => 'global', 'scope_id' => null, 'updated_by' => null, 'created_at' => $now, 'updated_at' => $now], ['%s','%s','%s','%d','%d','%s','%d','%d','%s','%s']); } }
}
