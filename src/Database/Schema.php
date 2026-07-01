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
}
