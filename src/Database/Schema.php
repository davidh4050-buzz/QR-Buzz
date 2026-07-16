<?php
namespace QRBuzz\Database;

class Schema {

    public static function qrcodesTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'qrbuzz_qrcodes';
    }

    public static function scansTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'qrbuzz_scans';
    }

    public static function destinationHistoryTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'qrbuzz_destination_history';
    }

    public static function campaignsTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'qrbuzz_campaigns';
    }

    public static function destinationRulesTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'qrbuzz_destination_rules';
    }

    public static function workspacesTable(): string {
        global $wpdb;
        return $wpdb->prefix . 'qrbuzz_workspaces';
    }
}
