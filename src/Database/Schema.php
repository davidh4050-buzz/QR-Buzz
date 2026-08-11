<?php
namespace QRBuzz\Database;

class Schema {

    public static function qrcodesTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_qrcodes'; }
    public static function scansTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_scans'; }
    public static function destinationHistoryTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_destination_history'; }
    public static function campaignsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_campaigns'; }
    public static function destinationRulesTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_destination_rules'; }
    public static function assetsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_assets'; }
    public static function workspacesTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_workspaces'; }
    public static function membershipsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_workspace_members'; }
    public static function profilesTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_user_profiles'; }
    public static function subscriptionsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_subscriptions'; }
    public static function webhookEventsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_webhook_events'; }
    public static function auditEventsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_audit_events'; }
    public static function platformEventsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_platform_events'; }
    public static function applicationErrorsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_application_errors'; }
    public static function webhookLogsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_webhook_logs'; }
    public static function featureFlagsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_feature_flags'; }
    public static function diagnosticSnapshotsTable(): string { global $wpdb; return $wpdb->prefix . 'qrbuzz_diagnostic_snapshots'; }
}
