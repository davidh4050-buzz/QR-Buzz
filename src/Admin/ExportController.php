<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\CampaignRepository;
use QRBuzz\Database\QRRepository;
use QRBuzz\Membership\EntitlementService;

class ExportController {

    private QRRepository $qrCodes;
    private CampaignRepository $campaigns;
    private EntitlementService $entitlements;

    public function __construct(?QRRepository $qrCodes = null, ?CampaignRepository $campaigns = null, ?EntitlementService $entitlements = null) {
        $this->qrCodes = $qrCodes ?: new QRRepository();
        $this->campaigns = $campaigns ?: new CampaignRepository();
        $this->entitlements = $entitlements ?: new EntitlementService();
    }

    public function init(): void {
        add_action('admin_post_qrbuzz_export_assets', [$this, 'assets']);
        add_action('admin_post_qrbuzz_export_campaigns', [$this, 'campaigns']);
    }

    public function assets(): void {
        $this->guard('qrbuzz_export_assets');
        $rows = [['ID', 'Name', 'Type', 'Shortcode', 'Destination', 'Campaign', 'Scans', 'Created']];
        foreach ($this->qrCodes->queryWithScanCounts('', 1, 10000) as $qrCode) {
            $rows[] = [$qrCode->id, $qrCode->name, $qrCode->type, $qrCode->shortcode, $qrCode->destinationUrl, $qrCode->campaignName ?: '', $qrCode->scanCount, $qrCode->createdAt];
        }
        $this->send('qr-buzz-assets.csv', $rows);
    }

    public function campaigns(): void {
        $this->guard('qrbuzz_export_campaigns');
        $rows = [['ID', 'Name', 'Slug', 'Status', 'QR Codes', 'Dynamic', 'Static', 'Scans']];
        foreach ($this->campaigns->all() as $campaign) {
            $rows[] = [$campaign->id, $campaign->name, $campaign->slug, $campaign->status, $campaign->qrCount, $campaign->dynamicCount, $campaign->staticCount, $campaign->scanCount];
        }
        $this->send('qr-buzz-campaigns.csv', $rows);
    }

    private function guard(string $nonceAction): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to export QR Buzz data.', 'qr-buzz'), 403);
        }
        check_admin_referer($nonceAction);
        if (!$this->entitlements->allows('csv_export')) {
            wp_die(esc_html__('CSV exports are not available on the current workspace plan.', 'qr-buzz'), 403);
        }
    }

    private function send(string $filename, array $rows): void {
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . sanitize_file_name($filename) . '"');
        $out = fopen('php://output', 'w');
        foreach ($rows as $row) {
            fputcsv($out, array_map([$this, 'safeCell'], $row));
        }
        fclose($out);
        exit;
    }

    private function safeCell($value): string {
        $value = (string) $value;
        if ($value !== '' && preg_match('/^[\x00-\x20]*[=+\-@]/', $value)) {
            return "'" . $value;
        }
        return $value;
    }
}
