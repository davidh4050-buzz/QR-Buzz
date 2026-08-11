<?php
namespace QRBuzz\Admin;

use QRBuzz\Analytics\AnalyticsRepository;
use QRBuzz\Analytics\CampaignAnalyticsService;
use QRBuzz\Analytics\DateRange;
use QRBuzz\Analytics\PerQRAnalyticsService;
use QRBuzz\Analytics\QRInsightService;
use QRBuzz\Database\CampaignRepository;
use QRBuzz\Database\QRRepository;
use QRBuzz\QR\QRGenerator;
use QRBuzz\QR\Types\QRTypeRegistry;

class AnalyticsPage {

    private AnalyticsRepository $analytics;
    private CampaignAnalyticsService $campaignAnalytics;
    private PerQRAnalyticsService $perQrAnalytics;
    private QRInsightService $insights;
    private QRRepository $qrCodes;
    private CampaignRepository $campaigns;
    private QRGenerator $generator;
    private QRTypeRegistry $types;

    public function __construct(?AnalyticsRepository $analytics = null, ?QRGenerator $generator = null, ?QRRepository $qrCodes = null, ?PerQRAnalyticsService $perQrAnalytics = null, ?QRInsightService $insights = null, ?QRTypeRegistry $types = null, ?CampaignRepository $campaigns = null, ?CampaignAnalyticsService $campaignAnalytics = null) {
        $this->analytics = $analytics ?: new AnalyticsRepository();
        $this->generator = $generator ?: new QRGenerator();
        $this->qrCodes = $qrCodes ?: new QRRepository();
        $this->perQrAnalytics = $perQrAnalytics ?: new PerQRAnalyticsService();
        $this->insights = $insights ?: new QRInsightService($this->perQrAnalytics);
        $this->types = $types ?: new QRTypeRegistry();
        $this->campaigns = $campaigns ?: new CampaignRepository();
        $this->campaignAnalytics = $campaignAnalytics ?: new CampaignAnalyticsService();
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        add_submenu_page('qr-buzz', 'QR Buzz Analytics', 'Analytics', 'manage_options', 'qr-buzz-analytics', [$this, 'render']);
    }

    public function assets(string $hook): void {
        if ($hook !== 'qr-buzz_page_qr-buzz-analytics') { return; }
        wp_register_style('qrbuzz-analytics', false, [], QR_BUZZ_VERSION);
        wp_enqueue_style('qrbuzz-analytics');
        wp_add_inline_style('qrbuzz-analytics', '.qrbuzz-analytics-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 24px}.qrbuzz-card{background:#fff;border:1px solid #c3c4c7;padding:14px}.qrbuzz-card strong{display:block;font-size:22px;line-height:1.25}.qrbuzz-section{margin-top:24px}.qrbuzz-chart{background:#fff;border:1px solid #c3c4c7;padding:16px;overflow:auto}.qrbuzz-bars{display:flex;align-items:flex-end;gap:8px;height:180px;min-width:520px}.qrbuzz-bar-wrap{flex:1;text-align:center}.qrbuzz-bar{display:block;background:#2271b1;min-height:2px}.qrbuzz-bar-label{display:block;margin-top:6px;font-size:11px;color:#50575e}.qrbuzz-breakdowns{display:grid;grid-template-columns:repeat(auto-fit,minmax(240px,1fr));gap:16px}.qrbuzz-empty{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0}.qrbuzz-filter{margin:16px 0}.qrbuzz-insights{background:#fff;border:1px solid #c3c4c7;padding:16px}.qrbuzz-insights li{margin-left:18px;list-style:disc}');
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access QR Buzz analytics.', 'qr-buzz'));
        }

        $campaignId = isset($_GET['campaign_id']) ? absint($_GET['campaign_id']) : 0;
        if ($campaignId > 0) { $this->renderCampaign($campaignId); return; }

        $qrId = isset($_GET['qr_id']) ? absint($_GET['qr_id']) : 0;
        if ($qrId > 0) { $this->renderSingle($qrId); return; }

        $this->renderGlobal();
    }

    private function renderGlobal(): void {
        $range = DateRange::fromRequest($_GET['range'] ?? '7days');
        $stats = $this->analytics->dashboardStats($range);
        $trend = $this->analytics->scanCountsByDay($range);
        $topQrCodes = $this->analytics->topQrCodes($range);
        $recentScans = $this->analytics->recentScans($range);
        $deviceBreakdown = $this->analytics->deviceBreakdown($range);
        $browserBreakdown = $this->analytics->browserBreakdown($range);

        echo '<div class="wrap"><h1>QR Buzz Analytics</h1>';
        $this->renderRangeFilter($range);
        if ((int) $stats['total_scans'] === 0) { echo '<div class="qrbuzz-empty"><strong>No scans in this range yet.</strong><p>Analytics will appear here once visitors scan your dynamic QR codes.</p></div>'; }
        $this->renderCards($stats, $range);
        $this->renderTrend($trend, $range);
        $this->renderTopQrCodes($topQrCodes);
        $this->renderRecentActivity($recentScans);
        $this->renderBreakdowns($deviceBreakdown, $browserBreakdown);
        echo '</div>';
    }

    private function renderCampaign(int $campaignId): void {
        $campaign = $this->campaigns->find($campaignId);
        echo '<div class="wrap"><h1>Campaign Analytics</h1><p><a href="' . esc_url(admin_url('admin.php?page=qr-buzz-campaigns')) . '">&larr; Back to Campaigns</a></p>';
        if (!$campaign) { echo '<div class="notice notice-error"><p>Campaign not found.</p></div></div>'; return; }
        $range = DateRange::fromRequest($_GET['range'] ?? '7days');
        $stats = $this->campaignAnalytics->stats($campaignId);
        echo '<h2>' . esc_html($campaign->name) . '</h2>';
        $this->renderCampaignRangeFilter($campaignId, $range);
        echo '<div class="qrbuzz-analytics-grid">';
        echo '<div class="qrbuzz-card"><span>Total QR Codes</span><strong>' . esc_html((string) $stats['total_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Dynamic QR Codes</span><strong>' . esc_html((string) $stats['dynamic_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Static QR Codes</span><strong>' . esc_html((string) $stats['static_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Total Scans</span><strong>' . esc_html((string) $stats['total_scans']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Scans Today</span><strong>' . esc_html((string) $stats['scans_today']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Last 7 Days</span><strong>' . esc_html((string) $stats['scans_last_7_days']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Last 30 Days</span><strong>' . esc_html((string) $stats['scans_last_30_days']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Top QR Code</span><strong>' . esc_html($stats['top_qr_code'] ? $stats['top_qr_code']->name : '-') . '</strong></div>';
        echo '</div>';
        if ((int) $stats['dynamic_qr_codes'] === 0 && (int) $stats['static_qr_codes'] > 0) { echo '<div class="qrbuzz-empty"><strong>Static-only campaign</strong><p>Static QR codes do not use QR Buzz tracking. Add a Dynamic URL QR code to collect campaign analytics.</p></div>'; }
        $this->renderInsights($this->campaignInsights($stats));
        $this->renderTrend($this->campaignAnalytics->scanCountsByDay($campaignId, $range), $range);
        $this->renderTopQrCodes($this->campaignAnalytics->topQrCodes($campaignId, $range));
        $this->renderRecentActivity($this->campaignAnalytics->recentScans($campaignId, $range));
        echo '<div class="qrbuzz-breakdowns">';
        $this->renderBreakdownTable('Referrers', $this->campaignAnalytics->referrerBreakdown($campaignId, $range));
        $this->renderBreakdownTable('Device Type', $this->campaignAnalytics->deviceBreakdown($campaignId, $range));
        $this->renderBreakdownTable('Browser', $this->campaignAnalytics->browserBreakdown($campaignId, $range));
        echo '</div></div>';
    }

    private function renderSingle(int $qrId): void {
        $qrCode = $this->qrCodes->find($qrId);
        echo '<div class="wrap"><h1>QR Analytics</h1>';
        echo '<p><a href="' . esc_url(admin_url('admin.php?page=qr-buzz-codes')) . '">&larr; Back to QR Codes</a></p>';

        if (!$qrCode) { echo '<div class="notice notice-error"><p>QR code not found.</p></div></div>'; return; }

        echo '<h2>' . esc_html($qrCode->name) . '</h2>';
        echo '<p><strong>Type:</strong> ' . esc_html($this->types->label($qrCode->type)) . '</p>';
        if ($qrCode->campaignName) { echo '<p><strong>Campaign:</strong> ' . esc_html($qrCode->campaignName) . '</p>'; }

        if (!$qrCode->isTrackable()) {
            echo '<div class="qrbuzz-empty"><strong>Static QR code</strong><p>Static QR codes do not use QR Buzz tracking. To collect analytics, create a Dynamic URL QR code.</p></div></div>';
            return;
        }

        $stats = $this->perQrAnalytics->stats($qrCode->id);
        echo '<p><strong>Tracking URL:</strong> <input class="regular-text" readonly value="' . esc_attr($this->generator->trackingUrl($qrCode->shortcode)) . '" /></p>';
        echo '<p><strong>Destination URL:</strong> <a href="' . esc_url($qrCode->destinationUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($qrCode->destinationUrl) . '</a></p>';
        echo '<div class="qrbuzz-analytics-grid">';
        echo '<div class="qrbuzz-card"><span>Total Scans</span><strong>' . esc_html((string) $stats['total_scans']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Today</span><strong>' . esc_html((string) $stats['scans_today']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Last 7 Days</span><strong>' . esc_html((string) $stats['scans_last_7_days']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Last 30 Days</span><strong>' . esc_html((string) $stats['scans_last_30_days']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Latest Scan</span><strong>' . esc_html($this->formatDate($stats['latest_scan'])) . '</strong></div>';
        echo '</div>';
        $this->renderInsights($this->insights->insights($qrCode));
        $this->renderTrend($this->perQrAnalytics->scanCountsByDay($qrCode->id, 30), DateRange::fromRequest('30days'));
        $this->renderRecentActivity($this->perQrAnalytics->recentScans($qrCode->id));
        echo '<div class="qrbuzz-breakdowns">';
        $this->renderBreakdownTable('Referrers', $this->perQrAnalytics->referrerBreakdown($qrCode->id));
        $this->renderBreakdownTable('Device Type', $this->perQrAnalytics->deviceBreakdown($qrCode->id));
        $this->renderBreakdownTable('Browser', $this->perQrAnalytics->browserBreakdown($qrCode->id));
        echo '</div></div>';
    }

    private function renderInsights(array $insights): void { echo '<div class="qrbuzz-section qrbuzz-insights"><h2>Insights</h2><ul>'; foreach ($insights as $insight) { echo '<li>' . esc_html($insight) . '</li>'; } echo '</ul></div>'; }
    private function renderRangeFilter(DateRange $range): void { echo '<form class="qrbuzz-filter" method="get"><input type="hidden" name="page" value="qr-buzz-analytics" /><label for="qrbuzz-range">Date range </label><select id="qrbuzz-range" name="range">'; foreach (DateRange::options() as $key => $label) { echo '<option value="' . esc_attr($key) . '" ' . selected($range->key, $key, false) . '>' . esc_html($label) . '</option>'; } echo '</select> '; submit_button('Apply', 'secondary', '', false); echo '</form>'; }
    private function renderCampaignRangeFilter(int $campaignId, DateRange $range): void { echo '<form class="qrbuzz-filter" method="get"><input type="hidden" name="page" value="qr-buzz-analytics" /><input type="hidden" name="campaign_id" value="' . esc_attr($campaignId) . '" /><label for="qrbuzz-range">Date range </label><select id="qrbuzz-range" name="range">'; foreach (DateRange::options() as $key => $label) { echo '<option value="' . esc_attr($key) . '" ' . selected($range->key, $key, false) . '>' . esc_html($label) . '</option>'; } echo '</select> '; submit_button('Apply', 'secondary', '', false); echo '</form>'; }

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
        echo '<div class="qrbuzz-section"><h2>Scan Trends</h2><div class="qrbuzz-chart" role="img" aria-label="Scan trend for ' . esc_attr($range->label) . '"><div class="qrbuzz-bars">';
        foreach ($trend as $point) { $height = $max > 0 ? max(2, (int) round(((int) $point['scans'] / $max) * 160)) : 2; echo '<div class="qrbuzz-bar-wrap"><span class="qrbuzz-bar" style="height:' . esc_attr((string) $height) . 'px" title="' . esc_attr($point['date'] . ': ' . $point['scans'] . ' scans') . '"></span><span class="qrbuzz-bar-label">' . esc_html(mysql2date('M j', $point['date'])) . '<br>' . esc_html((string) $point['scans']) . '</span></div>'; }
        echo '</div></div></div>';
    }

    private function renderTopQrCodes(array $topQrCodes): void {
        echo '<div class="qrbuzz-section"><h2>Top QR Codes</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Tracking URL</th><th>Destination URL</th><th>Total Scans</th><th>Last Scanned</th></tr></thead><tbody>';
        if (!$topQrCodes) { echo '<tr><td colspan="5">No scanned QR codes in this range.</td></tr>'; }
        foreach ($topQrCodes as $qrCode) { $trackingUrl = $this->generator->trackingUrl((string) $qrCode->shortcode); echo '<tr><td><strong><a href="' . esc_url(admin_url('admin.php?page=qr-buzz-analytics&qr_id=' . (int) $qrCode->id)) . '">' . esc_html($qrCode->name) . '</a></strong><br><code>' . esc_html($qrCode->shortcode) . '</code></td><td><input class="regular-text" readonly value="' . esc_attr($trackingUrl) . '" /></td><td><a href="' . esc_url($qrCode->destination_url) . '" target="_blank" rel="noopener noreferrer">' . esc_html($qrCode->destination_url) . '</a></td><td>' . esc_html((string) $qrCode->scan_count) . '</td><td>' . esc_html($this->formatDate((string) $qrCode->last_scan)) . '</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private function renderRecentActivity(array $recentScans): void {
        echo '<div class="qrbuzz-section"><h2>Recent Activity</h2><table class="widefat striped"><thead><tr><th>Activity</th><th>Referrer</th><th>User Agent</th><th>Country</th></tr></thead><tbody>';
        if (!$recentScans) { echo '<tr><td colspan="4">No recent scans.</td></tr>'; }
        foreach ($recentScans as $scan) { $name = isset($scan->qr_name) ? (string) $scan->qr_name : 'This QR code'; echo '<tr><td><strong>' . esc_html($name) . '</strong> scanned ' . esc_html(human_time_diff(strtotime((string) $scan->scanned_at), current_time('timestamp'))) . ' ago<br><span class="description">' . esc_html($this->formatDate((string) $scan->scanned_at)) . '</span></td><td>' . esc_html($scan->referrer ?: '-') . '</td><td>' . esc_html($scan->user_agent_summary) . '</td><td>' . esc_html($scan->country ?: '-') . '</td></tr>'; }
        echo '</tbody></table></div>';
    }

    private function renderBreakdowns(array $deviceBreakdown, array $browserBreakdown): void { echo '<div class="qrbuzz-section"><h2>Breakdowns</h2><div class="qrbuzz-breakdowns">'; $this->renderBreakdownTable('Device Type', $deviceBreakdown); $this->renderBreakdownTable('Browser', $browserBreakdown); echo '</div></div>'; }
    private function renderBreakdownTable(string $title, array $rows): void { echo '<div><h3>' . esc_html($title) . '</h3><table class="widefat striped"><thead><tr><th>Type</th><th>Scans</th></tr></thead><tbody>'; if (!$rows) { echo '<tr><td colspan="2">No data yet.</td></tr>'; } foreach ($rows as $row) { echo '<tr><td>' . esc_html($row['label']) . '</td><td>' . esc_html((string) $row['count']) . '</td></tr>'; } echo '</tbody></table></div>'; }

    private function campaignInsights(array $stats): array {
        $insights = [];
        if ((int) $stats['total_scans'] === 0) { $insights[] = 'This campaign has no scans yet.'; }
        if ((int) $stats['static_qr_codes'] > 0) { $insights[] = 'This campaign includes static QR codes that do not collect analytics.'; }
        if ((int) $stats['scans_last_7_days'] > (int) $stats['previous_7_days'] && (int) $stats['previous_7_days'] > 0) { $insights[] = 'Campaign scans are up compared with the previous 7 days.'; }
        if ((int) $stats['scans_last_7_days'] < (int) $stats['previous_7_days']) { $insights[] = 'Campaign scans are down compared with the previous 7 days.'; }
        return $insights ?: ['This campaign is ready to track dynamic QR scan activity.'];
    }

    private function formatDate(?string $date): string { if (!$date) { return '-'; } return mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $date); }
}
