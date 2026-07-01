<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\QRRepository;
use QRBuzz\Models\QRCode;

class AdminPage {

    private QRRepository $repository;

    public function __construct(?QRRepository $repository = null) {
        $this->repository = $repository ?: new QRRepository();
    }

    public function init(): void {
        add_action('admin_init', [$this, 'handleRequest']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
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

    public function assets(string $hook): void {
        if ($hook !== 'toplevel_page_qr-buzz') {
            return;
        }

        wp_register_script('qrbuzz-admin', '', [], QR_BUZZ_VERSION, true);
        wp_enqueue_script('qrbuzz-admin');
        wp_add_inline_script(
            'qrbuzz-admin',
            "document.addEventListener('click',function(event){var button=event.target.closest('.qrbuzz-copy');if(!button){return;}var value=button.dataset.qrbuzzCopy;if(!navigator.clipboard){window.prompt('Copy tracking URL',value);return;}navigator.clipboard.writeText(value).then(function(){button.textContent='Copied';setTimeout(function(){button.textContent='Copy tracking URL';},1600);}).catch(function(){window.prompt('Copy tracking URL',value);});});"
        );

        wp_register_style('qrbuzz-admin', false, [], QR_BUZZ_VERSION);
        wp_enqueue_style('qrbuzz-admin');
        wp_add_inline_style(
            'qrbuzz-admin',
            '.qrbuzz-dashboard{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 24px}.qrbuzz-card{background:#fff;border:1px solid #c3c4c7;padding:14px}.qrbuzz-card strong{display:block;font-size:22px;line-height:1.25}.qrbuzz-status{font-weight:600}.qrbuzz-status-active{color:#008a20}.qrbuzz-status-inactive{color:#8a2424}.qrbuzz-empty{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0}.column-preview{width:92px}.column-scan_count,.column-created_at,.column-active{width:110px}'
        );
    }

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access QR Buzz.', 'qr-buzz'));
        }

        $editing = $this->editingQrCode();
        $listTable = new QRCodeListTable($this->repository);
        $listTable->prepare_items();

        echo '<div class="wrap">';
        echo '<h1 class="wp-heading-inline">QR Buzz</h1>';
        $this->notice();
        $this->renderDashboard();
        $this->renderForm($editing);
        echo '<hr class="wp-header-end" />';
        echo '<h2>QR Codes</h2>';
        echo '<form method="get">';
        echo '<input type="hidden" name="page" value="qr-buzz" />';
        $listTable->search_box('Search QR codes', 'qrbuzz-search');
        $listTable->display();
        echo '</form>';
        echo '</div>';
    }

    public function handleRequest(): void {
        if (!$this->isQrBuzzPage() || !current_user_can('manage_options')) {
            return;
        }

        $action = isset($_REQUEST['qrbuzz_action']) ? sanitize_key(wp_unslash($_REQUEST['qrbuzz_action'])) : '';

        if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->saveQrCode();
        }

        if ($action === 'delete' && isset($_GET['qr_id'])) {
            $this->deleteQrCode((int) $_GET['qr_id']);
        }

        if (($action === 'activate' || $action === 'deactivate') && isset($_GET['qr_id'])) {
            $this->toggleQrCode((int) $_GET['qr_id'], $action === 'activate');
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

    private function toggleQrCode(int $id, bool $active): void {
        check_admin_referer('qrbuzz_toggle_qr_' . $id);
        $this->repository->setActive($id, $active);
        $this->redirectWithNotice($active ? 'activated' : 'deactivated');
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
            'activated' => ['success', 'QR code activated.'],
            'deactivated' => ['success', 'QR code deactivated.'],
            'invalid' => ['error', 'Please enter a name and a valid destination URL.'],
        ];

        if (!isset($messages[$notice])) {
            return;
        }

        [$type, $message] = $messages[$notice];
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function renderDashboard(): void {
        $summary = $this->repository->dashboardSummary();
        $mostScanned = $summary['most_scanned'];

        echo '<div class="qrbuzz-dashboard">';
        echo '<div class="qrbuzz-card"><span>Total QR Codes</span><strong>' . esc_html((string) $summary['total_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Total Scans</span><strong>' . esc_html((string) $summary['total_scans']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Most Scanned</span><strong>' . esc_html($mostScanned && $mostScanned->scanCount > 0 ? $mostScanned->name : '-') . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Latest Scan</span><strong>' . esc_html($this->formatDate($summary['latest_scan'])) . '</strong></div>';
        echo '</div>';

        if (!$summary['recent_qr_codes']) {
            echo '<div class="qrbuzz-empty"><strong>No QR codes yet.</strong><p>Create a QR code below to start tracking scans.</p></div>';
            return;
        }

        echo '<p><strong>Recently created:</strong> ';
        $recent = array_map(static fn(QRCode $qrCode): string => esc_html($qrCode->name), $summary['recent_qr_codes']);
        echo implode(', ', $recent);
        echo '</p>';
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
        echo '<tr><th scope="row"><label for="qrbuzz-destination">Destination URL</label></th><td><input id="qrbuzz-destination" class="regular-text" type="url" name="destination_url" value="' . esc_attr($destinationUrl) . '" required /><p class="description">Editing this URL will not change the tracking URL or shortcode.</p></td></tr>';
        echo '<tr><th scope="row">Status</th><td><label><input type="checkbox" name="active" value="1" ' . checked($active, true, false) . ' /> Active</label></td></tr>';
        echo '</tbody></table>';
        submit_button($isEditing ? 'Update QR Code' : 'Create QR Code');
        if ($isEditing) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=qr-buzz')) . '">Cancel edit</a></p>';
        }
        echo '</form>';
    }

    private function isQrBuzzPage(): bool {
        return isset($_REQUEST['page']) && sanitize_key(wp_unslash($_REQUEST['page'])) === 'qr-buzz';
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
