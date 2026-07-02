<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\QRRepository;
use QRBuzz\Models\QRCode;
use QRBuzz\Redirect\DestinationResolver;
use QRBuzz\Utils\DateTimeHelper;

class AdminPage {

    private QRRepository $repository;
    private DestinationResolver $resolver;

    public function __construct(?QRRepository $repository = null, ?DestinationResolver $resolver = null) {
        $this->repository = $repository ?: new QRRepository();
        $this->resolver = $resolver ?: new DestinationResolver();
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
            '.qrbuzz-dashboard{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 24px}.qrbuzz-card{background:#fff;border:1px solid #c3c4c7;padding:14px}.qrbuzz-card strong{display:block;font-size:22px;line-height:1.25}.qrbuzz-status{font-weight:600}.qrbuzz-status-active{color:#008a20}.qrbuzz-status-paused,.qrbuzz-status-expired{color:#8a2424}.qrbuzz-empty{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0}.qrbuzz-panel{background:#fff;border:1px solid #c3c4c7;margin:16px 0;padding:0 16px 8px}.qrbuzz-panel h3{border-bottom:1px solid #dcdcde;margin:0 -16px 12px;padding:12px 16px}.qrbuzz-preview{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.qrbuzz-preview div{background:#f6f7f7;padding:12px}.qrbuzz-history{margin-top:12px}.column-preview{width:92px}.column-scan_count,.column-created_at,.column-active{width:110px}'
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
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'active';
        $status = in_array($status, ['active', 'paused'], true) ? $status : 'active';
        $fallbackUrl = $this->optionalUrl('fallback_url');
        $scheduledUrl = $this->optionalUrl('scheduled_url');
        $expiresAt = $this->optionalDateTime('expires_at');
        $scheduledStartAt = $this->optionalDateTime('scheduled_start_at');
        $scheduledEndAt = $this->optionalDateTime('scheduled_end_at');

        if ($name === '' || $destinationUrl === '' || !wp_http_validate_url($destinationUrl)) {
            $this->redirectWithNotice('invalid');
        }

        if (!$this->optionalUrlIsValid('fallback_url') || !$this->optionalUrlIsValid('scheduled_url') || !$this->optionalDateTimeIsValid('expires_at') || !$this->optionalDateTimeIsValid('scheduled_start_at') || !$this->optionalDateTimeIsValid('scheduled_end_at')) {
            $this->redirectWithNotice('invalid_smart');
        }

        if (!$this->scheduleIsValid($scheduledUrl, $scheduledStartAt, $scheduledEndAt)) {
            $this->redirectWithNotice('invalid_schedule');
        }

        $settings = [
            'status' => $status,
            'fallback_url' => $fallbackUrl,
            'expires_at' => $expiresAt,
            'scheduled_url' => $scheduledUrl,
            'scheduled_start_at' => $scheduledStartAt,
            'scheduled_end_at' => $scheduledEndAt,
        ];

        if ($id > 0) {
            $this->repository->update($id, $name, $destinationUrl, $status, $settings, get_current_user_id());
            $this->redirectWithNotice('dynamic_updated');
        }

        $this->repository->create($name, $destinationUrl, $settings);
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
            'dynamic_updated' => ['success', 'QR code updated. The tracking URL and existing QR image are unchanged. Future scans will use the latest destination rules.'],
            'deleted' => ['success', 'QR code deleted.'],
            'activated' => ['success', 'QR code activated.'],
            'deactivated' => ['success', 'QR code paused.'],
            'invalid' => ['error', 'Please enter a name and a valid destination URL.'],
            'invalid_smart' => ['error', 'Please check the smart destination URLs and dates.'],
            'invalid_schedule' => ['error', 'A scheduled destination needs a URL, start time, and end time, with the end after the start.'],
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
        $status = $isEditing ? $editing->status : 'active';

        echo '<h2>' . esc_html($isEditing ? 'Edit QR Code' : 'Create QR Code') . '</h2>';
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=qr-buzz')) . '">';
        wp_nonce_field('qrbuzz_save_qr', 'qrbuzz_nonce');
        echo '<input type="hidden" name="qrbuzz_action" value="save" />';
        echo '<input type="hidden" name="qr_id" value="' . esc_attr($isEditing ? $editing->id : 0) . '" />';

        echo '<div class="qrbuzz-panel"><h3>Primary Destination</h3><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="qrbuzz-name">Name</label></th><td><input id="qrbuzz-name" class="regular-text" name="name" value="' . esc_attr($name) . '" required /></td></tr>';
        echo '<tr><th scope="row"><label for="qrbuzz-destination">Destination URL</label></th><td><input id="qrbuzz-destination" class="regular-text" type="url" name="destination_url" value="' . esc_attr($destinationUrl) . '" required /><p class="description">Print once. Change the destination anytime. Editing this URL will not change the tracking URL or shortcode.</p></td></tr>';
        echo '<tr><th scope="row"><label for="qrbuzz-status">Status</label></th><td><select id="qrbuzz-status" name="status"><option value="active" ' . selected($status, 'active', false) . '>Active</option><option value="paused" ' . selected($status, 'paused', false) . '>Paused</option></select><p class="description">Paused QR codes use the fallback URL when one is set, otherwise they show a simple paused message.</p></td></tr>';
        echo '</tbody></table></div>';

        echo '<div class="qrbuzz-panel"><h3>Smart Destination Settings</h3><table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="qrbuzz-scheduled-url">Scheduled destination URL</label></th><td><input id="qrbuzz-scheduled-url" class="regular-text" type="url" name="scheduled_url" value="' . esc_attr($isEditing ? (string) $editing->scheduledUrl : '') . '" /><p class="description">Used only between the schedule start and end times.</p></td></tr>';
        echo '<tr><th scope="row"><label for="qrbuzz-scheduled-start">Schedule start</label></th><td><input id="qrbuzz-scheduled-start" type="datetime-local" name="scheduled_start_at" value="' . esc_attr($isEditing ? DateTimeHelper::utcToLocalInput($editing->scheduledStartAt) : '') . '" /></td></tr>';
        echo '<tr><th scope="row"><label for="qrbuzz-scheduled-end">Schedule end</label></th><td><input id="qrbuzz-scheduled-end" type="datetime-local" name="scheduled_end_at" value="' . esc_attr($isEditing ? DateTimeHelper::utcToLocalInput($editing->scheduledEndAt) : '') . '" /></td></tr>';
        echo '<tr><th scope="row"><label for="qrbuzz-expires-at">Expiry date/time</label></th><td><input id="qrbuzz-expires-at" type="datetime-local" name="expires_at" value="' . esc_attr($isEditing ? DateTimeHelper::utcToLocalInput($editing->expiresAt) : '') . '" /><p class="description">After expiry, normal redirects stop and the fallback URL is used when available.</p></td></tr>';
        echo '<tr><th scope="row"><label for="qrbuzz-fallback-url">Fallback URL</label></th><td><input id="qrbuzz-fallback-url" class="regular-text" type="url" name="fallback_url" value="' . esc_attr($isEditing ? (string) $editing->fallbackUrl : '') . '" /><p class="description">Used when this QR code is paused, expired, or a scheduled destination is unavailable.</p></td></tr>';
        echo '</tbody></table></div>';

        if ($isEditing) {
            $this->renderRedirectPreview($editing);
        }

        submit_button($isEditing ? 'Update QR Code' : 'Create QR Code');
        if ($isEditing) {
            echo '<p><a href="' . esc_url(admin_url('admin.php?page=qr-buzz')) . '">Cancel edit</a></p>';
        }
        echo '</form>';

        if ($isEditing) {
            $this->renderHistory($editing);
        }
    }

    private function renderRedirectPreview(QRCode $qrCode): void {
        $resolution = $this->resolver->resolve($qrCode);
        $target = $resolution->shouldRedirect && $resolution->destinationUrl ? $resolution->destinationUrl : $resolution->message;

        echo '<div class="qrbuzz-panel"><h3>Redirect Preview</h3>';
        echo '<div class="qrbuzz-preview">';
        echo '<div><strong>Current WordPress time</strong><br>' . esc_html(DateTimeHelper::nowLocalDisplay()) . '</div>';
        echo '<div><strong>Resolved destination</strong><br>' . esc_html($target ?: '-') . '</div>';
        echo '<div><strong>Reason</strong><br>' . esc_html($this->label($resolution->reason)) . '</div>';
        echo '<div><strong>Active schedule</strong><br>' . esc_html($this->resolver->activeScheduleLabel($qrCode)) . '</div>';
        echo '<div><strong>Expiry</strong><br>' . esc_html(DateTimeHelper::utcToDisplay($qrCode->expiresAt)) . '</div>';
        echo '</div>';
        echo '<p><a class="button" href="' . esc_url(admin_url('admin.php?page=qr-buzz&qrbuzz_action=edit&qr_id=' . $qrCode->id)) . '">Test Redirect</a></p>';
        echo '</div>';
    }

    private function renderHistory(QRCode $qrCode): void {
        $history = $this->repository->destinationHistory($qrCode->id);

        echo '<div class="qrbuzz-panel qrbuzz-history"><h3>Destination History</h3>';
        echo '<table class="widefat striped"><thead><tr><th>Changed</th><th>Type</th><th>Previous</th><th>New</th><th>User</th></tr></thead><tbody>';

        if (!$history) {
            echo '<tr><td colspan="5">No destination changes recorded yet.</td></tr>';
        }

        foreach ($history as $row) {
            echo '<tr>';
            echo '<td>' . esc_html($this->formatDate((string) $row->changed_at)) . '</td>';
            echo '<td>' . esc_html($this->label((string) $row->change_type)) . '</td>';
            echo '<td>' . esc_html($row->previous_value ?: '-') . '</td>';
            echo '<td>' . esc_html($row->new_value ?: '-') . '</td>';
            echo '<td>' . esc_html($row->user_name ?: '-') . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    private function optionalUrl(string $field): ?string {
        $url = isset($_POST[$field]) ? esc_url_raw(wp_unslash($_POST[$field])) : '';

        return trim($url) === '' ? null : $url;
    }

    private function optionalUrlIsValid(string $field): bool {
        $url = isset($_POST[$field]) ? trim((string) wp_unslash($_POST[$field])) : '';

        return $url === '' || (bool) wp_http_validate_url(esc_url_raw($url));
    }

    private function optionalDateTime(string $field): ?string {
        $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';

        return DateTimeHelper::localInputToUtc($value);
    }

    private function optionalDateTimeIsValid(string $field): bool {
        $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : '';

        return trim($value) === '' || DateTimeHelper::localInputToUtc($value) !== null;
    }

    private function scheduleIsValid(?string $scheduledUrl, ?string $scheduledStartAt, ?string $scheduledEndAt): bool {
        $hasSchedule = $scheduledUrl || $scheduledStartAt || $scheduledEndAt;

        if (!$hasSchedule) {
            return true;
        }

        if (!$scheduledUrl || !$scheduledStartAt || !$scheduledEndAt) {
            return false;
        }

        return strtotime($scheduledEndAt . ' UTC') > strtotime($scheduledStartAt . ' UTC');
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

    private function label(string $value): string {
        return ucwords(str_replace('_', ' ', $value));
    }

    private function redirectWithNotice(string $notice): void {
        wp_safe_redirect(add_query_arg('qrbuzz_notice', $notice, admin_url('admin.php?page=qr-buzz')));
        exit;
    }
}
