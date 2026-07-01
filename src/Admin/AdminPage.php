<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\QRRepository;
use QRBuzz\Models\QRCode;
use QRBuzz\QR\QRGenerator;

class AdminPage {

    private QRRepository $repository;
    private QRGenerator $generator;

    public function __construct(?QRRepository $repository = null, ?QRGenerator $generator = null) {
        $this->repository = $repository ?: new QRRepository();
        $this->generator = $generator ?: new QRGenerator();
    }

    public function init(): void {
        add_action('admin_menu', [$this, 'menu']);
    }

    public function menu(): void {
        add_menu_page(
            'QR Buzz',
            'QR Buzz',
            'manage_options',
            'qr-buzz',
            [$this, 'render'],
            'dashicons-qr-code'
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access QR Buzz.', 'qr-buzz'));
        }

        $this->handleRequest();

        $editing = $this->editingQrCode();
        $qrCodes = $this->repository->allWithScanCounts();
        $recentScans = $this->repository->recentScans();
        $totalScans = array_sum(array_map(static fn(QRCode $qrCode): int => $qrCode->scanCount, $qrCodes));

        echo '<div class="wrap">';
        echo '<h1>QR Buzz</h1>';
        $this->notice();
        echo '<p><strong>' . esc_html(count($qrCodes)) . '</strong> QR codes &nbsp; <strong>' . esc_html($totalScans) . '</strong> total scans</p>';
        $this->renderForm($editing);
        $this->renderTable($qrCodes);
        $this->renderRecentScans($recentScans);
        echo '</div>';
    }

    private function handleRequest(): void {
        $action = isset($_REQUEST['qrbuzz_action']) ? sanitize_key(wp_unslash($_REQUEST['qrbuzz_action'])) : '';

        if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->saveQrCode();
        }

        if ($action === 'delete' && isset($_GET['qr_id'])) {
            $this->deleteQrCode((int) $_GET['qr_id']);
        }
    }

    private function saveQrCode(): void {
        check_admin_referer('qrbuzz_save_qr', 'qrbuzz_nonce');

        $id = isset($_POST['qr_id']) ? absint($_POST['qr_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $destinationUrl = isset($_POST['destination_url']) ? esc_url_raw(wp_unslash($_POST['destination_url'])) : '';
        $active = isset($_POST['active']);

        if ($name === '' || $destinationUrl === '' || !wp_http_validate_url($destinationUrl)) {
            $this->redirectWithNotice('invalid');
        }

        if ($id > 0) {
            $this->repository->update($id, $name, $destinationUrl, $active);
            $this->redirectWithNotice('updated');
        }

        $this->repository->create($name, $destinationUrl);
        $this->redirectWithNotice('created');
    }

    private function deleteQrCode(int $id): void {
        check_admin_referer('qrbuzz_delete_qr_' . $id);
        $this->repository->delete($id);
        $this->redirectWithNotice('deleted');
    }

    private function editingQrCode(): ?QRCode {
        if (!isset($_GET['qrbuzz_action'], $_GET['qr_id']) || $_GET['qrbuzz_action'] !== 'edit') {
            return null;
        }

        return $this->repository->find(absint($_GET['qr_id']));
    }

    private function notice(): void {
        $notice = isset($_GET['qrbuzz_notice']) ? sanitize_key(wp_unslash($_GET['qrbuzz_notice'])) : '';
        $messages = [
            'created' => ['success', 'QR code created.'],
            'updated' => ['success', 'QR code updated.'],
            'deleted' => ['success', 'QR code deleted.'],
            'invalid' => ['error', 'Please enter a name and a valid destination URL.'],
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        [$type, $message] = $messages[$notice];
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderForm(?QRCode $editing): void {
        $isEditing = $editing !== null;
        $name = $isEditing ? $editing->name : '';
        $destinationUrl = $isEditing ? $editing->destinationUrl : '';
        $active = !$isEditing || $editing->active;

        echo '<h2>' . esc_html($isEditing ? 'Edit QR Code' : 'Create QR Code') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=qr-buzz')) . '">';
        wp_nonce_field('qrbuzz_save_qr', 'qrbuzz_nonce');
        echo '<input type="hidden" name="qrbuzz_action" value="save" />';
        echo '<input type="hidden" name="qr_id" value="' . esc_attr($isEditing ? $editing->id : 0) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="qrbuzz-name">Name</label></th><td><input id="qrbuzz-name" class="regular-text" name="name" value="' . esc_attr($name) . '" required /></td></tr>';
        echo '<tr><th scope="row"><label for="qrbuzz-destination">Destination URL</label></th><td><input id="qrbuzz-destination" class="regular-text" type="url" name="destination_url" value="' . esc_attr($destinationUrl) . '" required /></td></tr>';
        echo '<tr><th scope="row">Active</th><td><label><input type="checkbox" name="active" value="1" ' . checked($active, true, false) . ' /> Enable redirects and scan tracking</label></td></tr>';
        echo '</tbody></table>';
        submit_button($isEditing ? 'Update QR Code' : 'Create QR Code');
        if ($isEditing) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=qr-buzz')) . '">Cancel edit</a></p>';
        }
        echo '</form>';
    }

    /**
     * @param QRCode[] $qrCodes
     */
    private function renderTable(array $qrCodes): void {
        echo '<h2>QR Codes</h2>';
        echo '<table class="widefat striped"><thead><tr>';
        echo '<th>Name</th><th>Tracking URL</th><th>QR</th><th>Scans</th><th>Last Scan</th><th>Status</th><th>Actions</th>';
        echo '</tr></thead><tbody>';

        if (!$qrCodes) {
            echo '<tr><td colspan="7">No QR codes yet.</td></tr>';
        }

        foreach ($qrCodes as $qrCode) {
            $trackingUrl = $this->generator->trackingUrl($qrCode->shortcode);
            $editUrl = admin_url('admin.php?page=qr-buzz&qrbuzz_action=edit&qr_id=' . $qrCode->id);
            $deleteUrl = wp_nonce_url(
                admin_url('admin.php?page=qr-buzz&qrbuzz_action=delete&qr_id=' . $qrCode->id),
                'qrbuzz_delete_qr_' . $qrCode->id
            );

            echo '<tr>';
            echo '<td><strong>' . esc_html($qrCode->name) . '</strong><br><a href="' . esc_url($qrCode->destinationUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($qrCode->destinationUrl) . '</a></td>';
            echo '<td><input class="regular-text" readonly value="' . esc_attr($trackingUrl) . '" /></td>';
            echo '<td>' . $this->qrImage($trackingUrl, $qrCode->name) . '</td>';
            echo '<td>' . esc_html($qrCode->scanCount) . '</td>';
            echo '<td>' . esc_html($this->formatDate($qrCode->lastScan)) . '</td>';
            echo '<td>' . esc_html($qrCode->active ? 'Active' : 'Paused') . '</td>';
            echo '<td><a href="' . esc_url($editUrl) . '">Edit</a> | <a href="' . esc_url($deleteUrl) . '" onclick="return confirm(\'Delete this QR code and its scan history?\');">Delete</a></td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    /**
     * @param object[] $recentScans
     */
    private function renderRecentScans(array $recentScans): void {
        echo '<h2>Recent Scans</h2>';
        echo '<table class="widefat striped"><thead><tr><th>QR Code</th><th>Scanned At</th><th>Referrer</th><th>User Agent</th></tr></thead><tbody>';

        if (!$recentScans) {
            echo '<tr><td colspan="4">No scans recorded yet.</td></tr>';
        }

        foreach ($recentScans as $scan) {
            echo '<tr>';
            echo '<td>' . esc_html($scan->qr_name) . ' <code>' . esc_html($scan->shortcode) . '</code></td>';
            echo '<td>' . esc_html($this->formatDate((string) $scan->scanned_at)) . '</td>';
            echo '<td>' . esc_html($scan->referrer ?: '-') . '</td>';
            echo '<td>' . esc_html(wp_trim_words((string) $scan->user_agent, 12, '...')) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
    }

    private function qrImage(string $trackingUrl, string $name): string {
        try {
            $src = $this->generator->generate($trackingUrl, 140);
            return '<img src="' . esc_attr($src) . '" width="140" height="140" alt="QR code for ' . esc_attr($name) . '" />';
        } catch (\Throwable $exception) {
            return '<span class="description">QR image unavailable.</span>';
        }
    }

    private function formatDate(?string $date): string {
        if (!$date) {
            return '-';
        }

        return mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $date);
    }

    private function redirectWithNotice(string $notice): void {
        wp_safe_redirect(add_query_arg('qrbuzz_notice', $notice, admin_url('admin.php?page=qr-buzz')));
        exit;
    }
}
