<?php
namespace QRBuzz\Platform;

use QRBuzz\Database\Schema;

class PlatformObservationService {

    public function observations(bool $refresh = false): array {
        $cached = get_transient('qrbuzz_platform_observations');
        if (!$refresh && is_array($cached)) { return $cached; }
        $items = $this->calculate();
        set_transient('qrbuzz_platform_observations', $items, 10 * MINUTE_IN_SECONDS);
        return $items;
    }

    private function calculate(): array {
        global $wpdb;
        $today = gmdate('Y-m-d 00:00:00', current_time('timestamp'));
        $yesterdayStart = gmdate('Y-m-d 00:00:00', strtotime('-1 day', current_time('timestamp')));
        $yesterdayEnd = gmdate('Y-m-d 23:59:59', strtotime('-1 day', current_time('timestamp')));
        $week = gmdate('Y-m-d H:i:s', strtotime('-7 days', current_time('timestamp')));
        $items = [];

        $newWorkspaces = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::workspacesTable() . ' WHERE created_at >= %s', $week));
        if ($newWorkspaces > 0) { $items[] = [$newWorkspaces . ' new workspace' . ($newWorkspaces === 1 ? '' : 's') . ' were created this week.', '/platform-admin/workspaces']; }

        $topType = $wpdb->get_row($wpdb->prepare('SELECT type, COUNT(*) AS total FROM ' . Schema::qrcodesTable() . ' WHERE created_at >= %s GROUP BY type ORDER BY total DESC LIMIT 1', $today));
        if ($topType && (int) $topType->total > 0) { $items[] = [ucwords(str_replace('_', ' ', (string) $topType->type)) . ' is today\'s most-used QR type.', '/platform-admin/qrs']; }

        $todayScans = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::scansTable() . ' WHERE scanned_at >= %s', $today));
        $yesterdayScans = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM ' . Schema::scansTable() . ' WHERE scanned_at BETWEEN %s AND %s', $yesterdayStart, $yesterdayEnd));
        if ($todayScans >= 10 && $yesterdayScans > 0) {
            $change = (($todayScans - $yesterdayScans) / max(1, $yesterdayScans)) * 100;
            if (abs($change) >= 20) { $items[] = ['Scan activity is ' . abs((int) round($change)) . '% ' . ($change > 0 ? 'higher' : 'lower') . ' than yesterday so far.', '/platform-admin/product-analytics']; }
        }

        $completed = (int) $wpdb->get_var($wpdb->prepare("SELECT COUNT(*) FROM " . Schema::workspacesTable() . " WHERE onboarding_status = 'complete' AND updated_at >= %s", $today));
        if ($completed > 0) { $items[] = [$completed . ' workspace' . ($completed === 1 ? '' : 's') . ' completed onboarding today.', '/platform-admin/workspaces']; }

        $topHour = $wpdb->get_row($wpdb->prepare('SELECT HOUR(scanned_at) AS scan_hour, COUNT(*) AS total FROM ' . Schema::scansTable() . ' WHERE scanned_at >= %s GROUP BY HOUR(scanned_at) ORDER BY total DESC LIMIT 1', $today));
        if ($topHour && (int) $topHour->total >= 3) { $items[] = ['The busiest scan hour today is ' . sprintf('%02d:00-%02d:00', (int) $topHour->scan_hour, ((int) $topHour->scan_hour + 1) % 24) . '.', '/platform-admin/product-analytics']; }

        if (!$items) { $items[] = ['There is not enough platform activity yet for a reliable observation. As users create and scan QR codes, this card will become more useful.', '/platform-admin/dashboard']; }
        return array_slice($items, 0, 4);
    }
}
