<?php
namespace QRBuzz\Admin;

use QRBuzz\Analytics\AnalyticsRepository;
use QRBuzz\Analytics\DateRange;
use QRBuzz\QR\QRGenerator;

class AnalyticsPage {

    private AnalyticsRepository $analytics;
    private QRGenerator $generator;

    public function __construct(?AnalyticsRepository $analytics = null, ?QRGenerator $generator = null) {
        $this->analytics = $analytics ?: new AnalyticsRepository();
        $this->generator = $generator ?: new QRGenerator();
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        add_submenu_page(
            'qr-buzz',
            'QR Buzz Analytics',
            'Analytics',
            'manage_options',
            'qr-buzz-analytics',
            [$this, 'render']
        );
    }

    public function assets(string $hook): void {
        if ($hook !== 'qr-buzz_page_qr-buzz-analytics') {
            return;
        }

        wp_register_style('qrbuzz-analytics', false, [], QR_BUZZ_VERSION);
        wp_enqueue_style('qrbuzz-analytics');
        wp_add_inline_style(
            'qrbuzz-analytics',
            '.qrbuzz-analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 24px}.qrbuzz-card{background:#fff;border:1px solid #c3c4c7;padding:14px}.qrbuzz-card strong{display:block;font-size:22px;line-height:1.25}.qrbuzz-section{margin-top:24px}.qrbuzz-chart{background:#fff;border:1px solid #c3c4c7;padding:16px;overflow:auto}.qrbuzz-bars{display:flex;align-items:flex-end;gap:8px;height:180px;min-width:520px}.qrbuzz-bar-wrap{flex:1;text-align:center}.qrbuzz-bar{display:block;background:#2271b1;min-height:2px}.qrbuzz-bar-label{display:block;margin-top:6px;font-size:11px;color:#50575e}.qrbuzz-breakdowns{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.qrbuzz-empty{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0}.qrbuzz-filter{margin:16px 0}'
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access QR Buzz analytics.', 'qr-buzz'));
        }

        $range = DateRange::fromRequest($_GET['range'] ?? '7days');
        $stats = $this->analytics->dashboardStats($range);
        $trend = $this->analytics->scanCountsByDay($range);
        $topQrCodes = $this->analytics->topQrCodes($range);
        $recentScans = $this->analytics->recentScans($range);
        $deviceBreakdown = $this->analytics->deviceBreakdown($range);
        $browserBreakdown = $this->analytics->browserBreakdown($range);

        echo '<div class="wrap">';
        echo '<h1>QR Buzz Analytics</h1>';
        $this->renderRangeFilter($range);

        if ((int) $stats['total_scans'] === 0) {
            echo '<div class="qrbuzz-empty"><strong>No scans in this range yet.</strong><p>Analytics will appear here once visitors scan your QR codes.</p></div>';
        }

        $this->renderCards($stats, $range);
        $this->renderTrend($trend, $range);
        $this->renderTopQrCodes($topQrCodes);
        $this->renderRecentActivity($recentScans);
        $this->renderBreakdowns($deviceBreakdown, $browserBreakdown);
        echo '</div>';
    }

    private function renderRangeFilter(DateRange $range): void {
        echo '<form class="qrbuzz-filter" method="get">';
        echo '<input type="hidden" name="page" value="qr-buzz-analytics" />';
        echo '<label for="qrbuzz-range">Date range </label>';
        echo '<select id="qrbuzz-range" name="range">';

        foreach (DateRange::options() as $key => $label) {
            echo '<option value="' . esc_attr($key) . '" ' . selected($range->key, $key, false) . '>' . esc_html($label) . '</option>';
        }

        echo '</select> ';
        submit_button('Apply', 'secondary', '', false);
        echo '</form>';
    }

    private function renderCards(array $stats, DateRange $range): void {
        $mostScanned = $stats['most_scanned'];

        echo '<div class="qrbuzz-analytics-grid">';
        echo '<div class="qrbuzz-card"><span>Total QR Codes</span><strong>' . esc_html((string) $stats['total_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Scans (' . esc_html($range->label) . ')</span><strong>' . esc_html((string) $stats['total_scans']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Scans Today</span><strong>' . esc_html((string) $stats['scans_today']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Last 7 Days</span><strong>' . esc_html((string) $stats['scans_last_7_days']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Most Scanned</span><strong>' . esc_html($mostScanned ? $mostScanned->name : '-') . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Latest Scan</span><strong>' . esc_html($this->formatDate($stats['latest_scan'])) . '</strong></div>';
        echo '</div>';
    }

    private function renderTrend(array $trend, DateRange $range): void {
        $max = max(array_column($trend, 'scans') ?: [0]);

        echo '<div class="qrbuzz-section">';
        echo '<h2>Scan Trends</h2>';
        echo '<div class="qrbuzz-chart" role="img" aria-label="Scan trend for ' . esc_attr($range->label) . '">';
        echo '<div class="qrbuzz-bars">';

        foreach ($trend as $point) {
            $height = $max > 0 ? max(2, (int) round(($point['scans'] / $max) * 160)) : 2;
            echo '<div class="qrbuzz-bar-wrap">';
            echo '<span class="qrbuzz-bar" style="height:' . esc_attr((string) $height) . 'px" title="' . esc_attr($point['date'] . ': ' . $point['scans'] . ' scans') . '"></span>';
            echo '<span class="qrbuzz-bar-label">' . esc_html(mysql2date('M j', $point['date'])) . '<br>' . esc_html((string) $point['scans']) . '</span>';
            echo '</div>';
        }

        echo '</div></div></div>';
    }

    private function renderTopQrCodes(array $topQrCodes): void {
        echo '<div class="qrbuzz-section">';
        echo '<h2>Top QR Codes</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Name</th><th>Tracking URL</th><th>Destination URL</th><th>Total Scans</th><th>Last Scanned</th></tr></thead><tbody>';

        if (!$topQrCodes) {
            echo '<tr><td colspan="5">No scanned QR codes in this range.</td></tr>';
        }

        foreach ($topQrCodes as $qrCode) {
            $trackingUrl = $this->generator->trackingUrl((string) $qrCode->shortcode);
            echo '<tr>';
            echo '<td><strong>' . esc_html($qrCode->name) . '</strong><br><code>' . esc_html($qrCode->shortcode) . '</code></td>';
            echo '<td><input class="regular-text" readonly value="' . esc_attr($trackingUrl) . '" /></td>';
            echo '<td><a href="' . esc_url($qrCode->destination_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($qrCode->destination_url) . '</a></td>';
            echo '<td>' . esc_html((string) $qrCode->scan_count) . '</td>';
            echo '<td>' . esc_html($this->formatDate((string) $qrCode->last_scan)) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function renderRecentActivity(array $recentScans): void {
        echo '<div class="qrbuzz-section">';
        echo '<h2>Recent Activity</h2>';
        echo '<table class="widefat striped"><thead><tr><th>Activity</th><th>Referrer</th><th>User Agent</th><th>Country</th></tr></thead><tbody>';

        if (!$recentScans) {
            echo '<tr><td colspan="4">No recent scans in this range.</td></tr>';
        }

        foreach ($recentScans as $scan) {
            echo '<tr>';
            echo '<td><strong>' . esc_html($scan->qr_name) . '</strong> scanned ' . esc_html(human_time_diff(strtotime((string) $scan->scanned_at), current_time('timestamp'))) . ' ago<br><span class="description">' . esc_html($this->formatDate((string) $scan->scanned_at)) . '</span></td>';
            echo '<td>' . esc_html($scan->referrer ?: '-') . '</td>';
            echo '<td>' . esc_html($scan->user_agent_summary) . '</td>';
            echo '<td>' . esc_html($scan->country ?: '-') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function renderBreakdowns(array $deviceBreakdown, array $browserBreakdown): void {
        echo '<div class="qrbuzz-section">';
        echo '<h2>Breakdowns</h2>';
        echo '<div class="qrbuzz-breakdowns">';
        $this->renderBreakdownTable('Device Type', $deviceBreakdown);
        $this->renderBreakdownTable('Browser', $browserBreakdown);
        echo '</div></div>';
    }

    private function renderBreakdownTable(string $title, array $rows): void {
        echo '<div>'; 
        echo '<h3>' . esc_html($title) . '</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Type</th><th>Scans</th></tr></thead><tbody>';

        if (!$rows) {
            echo '<tr><td colspan="2">No data yet.</td></tr>';
        }

        foreach ($rows as $row) {
            echo '<tr><td>' . esc_html($row['label']) . '</td><td>' . esc_html((string) $row['count']) . '</td></tr>';
        }

        echo '</tbody></table></div>';
    }

    private function formatDate(?string $date): string {
        if (!$date) {
            return '-';
        }

        return mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $date);
    }
}
