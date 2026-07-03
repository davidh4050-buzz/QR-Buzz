<?php
namespace QRBuzz\Admin;

use QRBuzz\Core\Requirements;
use QRBuzz\Database\QRRepository;
use QRBuzz\Models\QRCode;
use QRBuzz\QR\QRGenerator;
use QRBuzz\QR\Types\QRPayloadService;
use QRBuzz\QR\Types\QRTypeRegistry;

if (!class_exists('WP_List_Table')) {
    require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

class QRCodeListTable extends \WP_List_Table {

    private QRRepository $repository;
    private QRGenerator $generator;
    private QRPayloadService $payloads;
    private QRTypeRegistry $types;

    public function __construct(QRRepository $repository, ?QRGenerator $generator = null, ?QRPayloadService $payloads = null, ?QRTypeRegistry $types = null) {
        parent::__construct(['singular' => 'qr_code', 'plural' => 'qr_codes', 'ajax' => false]);
        $this->repository = $repository;
        $this->generator = $generator ?: new QRGenerator();
        $this->types = $types ?: new QRTypeRegistry();
        $this->payloads = $payloads ?: new QRPayloadService($this->types, $this->generator);
    }

    public function prepare_items(): void {
        $perPage = 10;
        $currentPage = $this->get_pagenum();
        $search = isset($_REQUEST['s']) ? sanitize_text_field(wp_unslash($_REQUEST['s'])) : '';
        $orderby = isset($_REQUEST['orderby']) ? sanitize_key(wp_unslash($_REQUEST['orderby'])) : 'created_at';
        $order = isset($_REQUEST['order']) ? sanitize_key(wp_unslash($_REQUEST['order'])) : 'desc';
        $totalItems = $this->repository->count($search);

        $this->_column_headers = [$this->get_columns(), [], $this->get_sortable_columns()];
        $this->items = $this->repository->queryWithScanCounts($search, $currentPage, $perPage, $orderby, $order);
        $this->set_pagination_args(['total_items' => $totalItems, 'per_page' => $perPage, 'total_pages' => max(1, (int) ceil($totalItems / $perPage))]);
    }

    public function get_columns(): array {
        return ['preview' => 'QR', 'name' => 'Name', 'type' => 'Type', 'destination_url' => 'Payload / Destination', 'tracking_url' => 'Tracking', 'scan_count' => 'Scans', 'created_at' => 'Created', 'active' => 'Status'];
    }

    public function get_sortable_columns(): array {
        return ['name' => ['name', false], 'type' => ['type', false], 'scan_count' => ['scan_count', true], 'created_at' => ['created_at', true], 'active' => ['active', false]];
    }

    public function no_items(): void { esc_html_e('No QR codes found. Create your first QR code above.', 'qr-buzz'); }

    public function column_preview(QRCode $item): string {
        if (!Requirements::dependenciesLoaded()) { return '<span class="description">Unavailable</span>'; }
        try {
            return '<img src="' . esc_attr($this->generator->generatePngDataUri($this->payloads->payloadForQrCode($item), 80)) . '" width="80" height="80" alt="" />';
        } catch (\Throwable $exception) {
            return '<span class="description">Unavailable</span>';
        }
    }

    public function column_name(QRCode $item): string {
        $editUrl = admin_url('admin.php?page=qr-buzz&qrbuzz_action=edit&qr_id=' . $item->id);
        $deleteUrl = wp_nonce_url(admin_url('admin.php?page=qr-buzz&qrbuzz_action=delete&qr_id=' . $item->id), 'qrbuzz_delete_qr_' . $item->id);
        $pngUrl = $this->downloadUrl($item, 'png');
        $svgUrl = $this->generator->supportsSvg() ? $this->downloadUrl($item, 'svg') : '';
        $actions = ['edit' => '<a href="' . esc_url($editUrl) . '">Edit</a>'];

        if ($item->isTrackable()) {
            $isPaused = $item->effectiveStatus() === 'paused';
            $toggleUrl = wp_nonce_url(admin_url('admin.php?page=qr-buzz&qrbuzz_action=' . ($isPaused ? 'activate' : 'deactivate') . '&qr_id=' . $item->id), 'qrbuzz_toggle_qr_' . $item->id);
            $analyticsUrl = admin_url('admin.php?page=qr-buzz-analytics&qr_id=' . $item->id);
            $actions['analytics'] = '<a href="' . esc_url($analyticsUrl) . '">View Analytics</a>';
            $actions['toggle'] = '<a href="' . esc_url($toggleUrl) . '">' . esc_html($isPaused ? 'Activate' : 'Pause') . '</a>';
            $actions['copy'] = '<button type="button" class="button-link qrbuzz-copy" data-qrbuzz-copy="' . esc_attr($this->trackingUrl($item)) . '">Copy tracking URL</button>';
        }

        $actions['download_png'] = '<a href="' . esc_url($pngUrl) . '">Download PNG</a>';
        if ($svgUrl !== '') { $actions['download_svg'] = '<a href="' . esc_url($svgUrl) . '">Download SVG</a>'; }
        $actions['delete'] = '<a href="' . esc_url($deleteUrl) . '" onclick="return confirm(&quot;Delete this QR code and its scan history?&quot;);">Delete</a>';

        return '<strong><a href="' . esc_url($editUrl) . '">' . esc_html($item->name) . '</a></strong>' . $this->row_actions($actions);
    }

    public function column_type(QRCode $item): string { return esc_html($this->types->label($item->type)); }

    public function column_destination_url(QRCode $item): string {
        if ($item->isTrackable()) {
            return '<a href="' . esc_url($item->destinationUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($item->destinationUrl) . '</a>';
        }

        return '<code>' . esc_html(wp_html_excerpt((string) $item->staticPayload, 90, '...')) . '</code>';
    }

    public function column_tracking_url(QRCode $item): string {
        if (!$item->isTrackable()) {
            return '<span class="description">Static - not tracked</span>';
        }

        return '<input class="regular-text" readonly value="' . esc_attr($this->trackingUrl($item)) . '" />';
    }

    public function column_scan_count(QRCode $item): string { return $item->isTrackable() ? esc_html((string) $item->scanCount) : '-'; }
    public function column_created_at(QRCode $item): string { return esc_html(mysql2date(get_option('date_format'), $item->createdAt)); }

    public function column_active(QRCode $item): string {
        if (!$item->isTrackable()) { return '<span class="qrbuzz-status">Static</span>'; }
        $status = $item->effectiveStatus();
        $labels = ['active' => 'Active', 'paused' => 'Paused', 'expired' => 'Expired'];
        return '<span class="qrbuzz-status qrbuzz-status-' . esc_attr($status) . '">' . esc_html($labels[$status] ?? 'Active') . '</span>';
    }

    public function column_default($item, $columnName): string { return isset($item->{$columnName}) ? esc_html((string) $item->{$columnName}) : ''; }

    private function trackingUrl(QRCode $qrCode): string { return $this->generator->trackingUrl($qrCode->shortcode); }

    private function downloadUrl(QRCode $qrCode, string $format): string {
        return wp_nonce_url(admin_url('admin-post.php?action=qrbuzz_download_' . $format . '&qr_id=' . $qrCode->id), 'qrbuzz_download_qr_' . $qrCode->id);
    }
}
