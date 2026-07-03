<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\CampaignRepository;
use QRBuzz\Database\DestinationRuleRepository;
use QRBuzz\Database\QRRepository;
use QRBuzz\Models\DestinationRule;
use QRBuzz\Models\QRCode;
use QRBuzz\QR\Types\QRPayloadService;
use QRBuzz\QR\Types\QRTypeRegistry;
use QRBuzz\Redirect\DestinationResolver;
use QRBuzz\Utils\DateTimeHelper;

class AdminPage {

    private QRRepository $repository;
    private DestinationResolver $resolver;
    private QRTypeRegistry $types;
    private QRPayloadService $payloads;
    private CampaignRepository $campaigns;
    private DestinationRuleRepository $rules;

    public function __construct(?QRRepository $repository = null, ?DestinationResolver $resolver = null, ?QRTypeRegistry $types = null, ?QRPayloadService $payloads = null, ?CampaignRepository $campaigns = null, ?DestinationRuleRepository $rules = null) {
        $this->repository = $repository ?: new QRRepository();
        $this->rules = $rules ?: new DestinationRuleRepository();
        $this->resolver = $resolver ?: new DestinationResolver($this->rules);
        $this->types = $types ?: new QRTypeRegistry();
        $this->payloads = $payloads ?: new QRPayloadService($this->types);
        $this->campaigns = $campaigns ?: new CampaignRepository();
    }

    public function init(): void {
        add_action('admin_init', [$this, 'handleRequest']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        add_menu_page('QR Buzz', 'QR Buzz', 'manage_options', 'qr-buzz', [$this, 'renderDashboardPage'], 'dashicons-qr-code');
        add_submenu_page('qr-buzz', 'QR Buzz Dashboard', 'Dashboard', 'manage_options', 'qr-buzz', [$this, 'renderDashboardPage']);
        add_submenu_page('qr-buzz', 'QR Codes', 'QR Codes', 'manage_options', 'qr-buzz-codes', [$this, 'renderCodesPage']);
        add_submenu_page('qr-buzz', 'Create QR', 'Create QR', 'manage_options', 'qr-buzz-create', [$this, 'renderCreatePage']);
        add_submenu_page('qr-buzz', 'QR Buzz Settings', 'Settings', 'manage_options', 'qr-buzz-settings', [$this, 'renderSettingsPage']);
    }

    public function assets(string $hook): void {
        if (!in_array($hook, ['toplevel_page_qr-buzz', 'qr-buzz_page_qr-buzz-codes', 'qr-buzz_page_qr-buzz-create', 'qr-buzz_page_qr-buzz-settings'], true)) { return; }
        wp_register_script('qrbuzz-admin', '', [], QR_BUZZ_VERSION, true);
        wp_enqueue_script('qrbuzz-admin');
        wp_add_inline_script('qrbuzz-admin', "document.addEventListener('click',function(event){var button=event.target.closest('.qrbuzz-copy');if(!button){return;}var value=button.dataset.qrbuzzCopy;if(!navigator.clipboard){window.prompt('Copy tracking URL',value);return;}navigator.clipboard.writeText(value).then(function(){button.textContent='Copied';setTimeout(function(){button.textContent='Copy tracking URL';},1600);}).catch(function(){window.prompt('Copy tracking URL',value);});});document.addEventListener('change',function(event){if(event.target.id!=='qrbuzz-type'){return;}document.querySelectorAll('[data-qrbuzz-type-fields]').forEach(function(el){el.style.display=el.dataset.qrbuzzTypeFields===event.target.value?'':'none';});});document.addEventListener('DOMContentLoaded',function(){var select=document.getElementById('qrbuzz-type');if(select){select.dispatchEvent(new Event('change'));}});");
        wp_register_style('qrbuzz-admin', false, [], QR_BUZZ_VERSION);
        wp_enqueue_style('qrbuzz-admin');
        wp_add_inline_style('qrbuzz-admin', '.qrbuzz-dashboard{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px;margin:16px 0 24px}.qrbuzz-card{background:#fff;border:1px solid #c3c4c7;padding:14px}.qrbuzz-card strong{display:block;font-size:22px;line-height:1.25}.qrbuzz-actions{display:flex;gap:8px;flex-wrap:wrap;margin:16px 0}.qrbuzz-status{font-weight:600}.qrbuzz-status-active{color:#008a20}.qrbuzz-status-paused,.qrbuzz-status-expired{color:#8a2424}.qrbuzz-empty,.qrbuzz-panel{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0}.qrbuzz-panel h3{margin-top:0}.qrbuzz-preview{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:12px}.qrbuzz-preview div{background:#f6f7f7;padding:12px}.qrbuzz-type-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:10px}.qrbuzz-type-grid label{display:block;background:#fff;border:1px solid #c3c4c7;padding:12px}.qrbuzz-rule-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:10px}.column-preview{width:92px}.column-scan_count,.column-created_at,.column-active{width:110px}');
    }

    public function renderDashboardPage(): void {
        $this->guard();
        echo '<div class="wrap"><h1>QR Buzz</h1>';
        $this->notice();
        $summary = $this->repository->dashboardSummary();
        $mostScanned = $summary['most_scanned'];
        echo '<div class="qrbuzz-dashboard">';
        echo '<div class="qrbuzz-card"><span>Total QR Codes</span><strong>' . esc_html((string) $summary['total_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Dynamic QR Codes</span><strong>' . esc_html((string) $summary['dynamic_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Static QR Codes</span><strong>' . esc_html((string) $summary['static_qr_codes']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Total Scans</span><strong>' . esc_html((string) $summary['total_scans']) . '</strong></div>';
        echo '<div class="qrbuzz-card"><span>Top QR Code</span><strong>' . esc_html($mostScanned && $mostScanned->scanCount > 0 ? $mostScanned->name : '-') . '</strong></div>';
        echo '</div><div class="qrbuzz-actions">';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=qr-buzz-codes')) . '">QR Codes</a>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=qr-buzz-campaigns')) . '">Campaigns</a>';
        echo '<a class="button button-primary" href="' . esc_url(admin_url('admin.php?page=qr-buzz-create&type=dynamic_url')) . '">Create Dynamic QR</a>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=qr-buzz-create&type=wifi')) . '">Create WiFi QR</a>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=qr-buzz-create&type=vcard')) . '">Create Business Card QR</a>';
        echo '<a class="button" href="' . esc_url(admin_url('admin.php?page=qr-buzz-analytics')) . '">View Analytics</a></div>';
        $this->renderRecentActivity($summary['recent_scans']);
        echo '</div>';
    }

    public function renderCodesPage(): void {
        $this->guard();
        $editing = $this->editingQrCode();
        echo '<div class="wrap"><h1>QR Codes</h1>';
        $this->notice();
        if ($editing) { $this->renderForm($editing); }
        $listTable = new QRCodeListTable($this->repository, null, null, null, $this->campaigns);
        $listTable->prepare_items();
        echo '<form method="get"><input type="hidden" name="page" value="qr-buzz-codes" />';
        $listTable->search_box('Search QR codes', 'qrbuzz-search');
        $listTable->display();
        echo '</form></div>';
    }

    public function renderCreatePage(): void { $this->guard(); echo '<div class="wrap"><h1>Create QR</h1>'; $this->notice(); $this->renderForm(null); echo '</div>'; }
    public function renderSettingsPage(): void { $this->guard(); echo '<div class="wrap"><h1>QR Buzz Settings</h1><div class="qrbuzz-empty"><p>Settings will be added in a future release.</p></div></div>'; }

    public function handleRequest(): void {
        if (!$this->isQrBuzzPage() || !current_user_can('manage_options')) { return; }
        $action = isset($_REQUEST['qrbuzz_action']) ? sanitize_key(wp_unslash($_REQUEST['qrbuzz_action'])) : '';
        if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') { $this->saveQrCode(); }
        if ($action === 'save_rule' && $_SERVER['REQUEST_METHOD'] === 'POST') { $this->saveRule(); }
        if ($action === 'delete_rule' && isset($_GET['rule_id'])) { $this->deleteRule(absint($_GET['rule_id'])); }
        if (($action === 'activate_rule' || $action === 'deactivate_rule') && isset($_GET['rule_id'])) { $this->toggleRule(absint($_GET['rule_id']), $action === 'activate_rule'); }
        if ($action === 'delete' && isset($_GET['qr_id'])) { $this->deleteQrCode((int) $_GET['qr_id']); }
        if (($action === 'activate' || $action === 'deactivate') && isset($_GET['qr_id'])) { $this->toggleQrCode((int) $_GET['qr_id'], $action === 'activate'); }
    }

    private function saveQrCode(): void {
        check_admin_referer('qrbuzz_save_qr', 'qrbuzz_nonce');
        $id = isset($_POST['qr_id']) ? absint($_POST['qr_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $type = $this->types->normalize(isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : 'dynamic_url');
        $payloadData = $this->payloadDataFromPost($type);
        $staticPayload = $this->payloads->build($type, $payloadData);
        $destinationUrl = $type === 'dynamic_url' ? esc_url_raw((string) ($payloadData['destination_url'] ?? '')) : ($type === 'static_url' ? esc_url_raw((string) ($payloadData['url'] ?? '')) : '');
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'active';
        $status = in_array($status, ['active', 'paused'], true) ? $status : 'active';

        if ($name === '' || !$this->payloads->isPayloadValid($type, $staticPayload)) { $this->redirectWithNotice('invalid'); }

        $fallbackUrl = $this->optionalUrl('fallback_url');
        $scheduledUrl = $this->optionalUrl('scheduled_url');
        $expiresAt = $this->optionalDateTime('expires_at');
        $scheduledStartAt = $this->optionalDateTime('scheduled_start_at');
        $scheduledEndAt = $this->optionalDateTime('scheduled_end_at');
        if ($type === 'dynamic_url' && (!$this->optionalUrlIsValid('fallback_url') || !$this->optionalUrlIsValid('scheduled_url') || !$this->optionalDateTimeIsValid('expires_at') || !$this->optionalDateTimeIsValid('scheduled_start_at') || !$this->optionalDateTimeIsValid('scheduled_end_at'))) { $this->redirectWithNotice('invalid_smart'); }
        if ($type === 'dynamic_url' && !$this->scheduleIsValid($scheduledUrl, $scheduledStartAt, $scheduledEndAt)) { $this->redirectWithNotice('invalid_schedule'); }

        $settings = ['type' => $type, 'payload_data' => $payloadData, 'static_payload' => $staticPayload, 'status' => $status, 'fallback_url' => $fallbackUrl, 'expires_at' => $expiresAt, 'scheduled_url' => $scheduledUrl, 'scheduled_start_at' => $scheduledStartAt, 'scheduled_end_at' => $scheduledEndAt, 'campaign_id' => isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0];
        if ($id > 0) { $this->repository->update($id, $name, $destinationUrl, $status, $settings, get_current_user_id()); $this->redirectWithNotice('dynamic_updated'); }
        $this->repository->create($name, $destinationUrl, $settings);
        $this->redirectWithNotice('created');
    }

    private function renderForm(?QRCode $editing): void {
        $isEditing = $editing !== null;
        $selectedType = $isEditing ? $editing->type : $this->types->normalize(isset($_GET['type']) ? sanitize_key(wp_unslash($_GET['type'])) : 'dynamic_url');
        $payload = $isEditing ? $editing->payloadData : [];
        if ($isEditing && !$payload) { $payload = ['destination_url' => $editing->destinationUrl, 'url' => $editing->destinationUrl]; }
        echo '<form method="post" action="' . esc_url(admin_url('admin.php?page=qr-buzz-create')) . '">';
        wp_nonce_field('qrbuzz_save_qr', 'qrbuzz_nonce');
        echo '<input type="hidden" name="qrbuzz_action" value="save" /><input type="hidden" name="qr_id" value="' . esc_attr($isEditing ? $editing->id : 0) . '" />';
        echo '<div class="qrbuzz-panel"><h3>QR Details</h3><table class="form-table"><tbody>';
        echo '<tr><th><label for="qrbuzz-name">Name</label></th><td><input id="qrbuzz-name" class="regular-text" name="name" value="' . esc_attr($isEditing ? $editing->name : '') . '" required /></td></tr>';
        $this->campaignField($editing ? $editing->campaignId : null);
        echo '<tr><th><label for="qrbuzz-type">Type</label></th><td><select id="qrbuzz-type" name="type">';
        foreach ($this->types->all() as $key => $type) { echo '<option value="' . esc_attr($key) . '" ' . selected($selectedType, $key, false) . '>' . esc_html($type['label']) . '</option>'; }
        echo '</select></td></tr></tbody></table></div>';
        $this->renderTypeFields($selectedType, $payload, $editing);
        if ($isEditing && $editing->isTrackable()) { $this->renderRedirectPreview($editing); }
        submit_button($isEditing ? 'Update QR Code' : 'Create QR Code');
        if ($isEditing) { echo '<p><a href="' . esc_url(admin_url('admin.php?page=qr-buzz-codes')) . '">Cancel edit</a></p>'; }
        echo '</form>';
        if ($isEditing && $editing->isTrackable()) { $this->renderRules($editing); $this->renderHistory($editing); }
    }

    private function renderTypeFields(string $selectedType, array $payload, ?QRCode $editing): void {
        foreach (array_keys($this->types->all()) as $type) {
            echo '<div class="qrbuzz-panel" data-qrbuzz-type-fields="' . esc_attr($type) . '" style="display:' . ($selectedType === $type ? '' : 'none') . '"><h3>' . esc_html($this->types->label($type)) . '</h3><table class="form-table"><tbody>';
            if ($type === 'dynamic_url') { $this->field('Destination URL', 'payload[destination_url]', $payload['destination_url'] ?? ($editing ? $editing->destinationUrl : ''), 'url'); $this->renderDynamicSettings($editing); }
            if ($type === 'static_url') { $this->field('URL', 'payload[url]', $payload['url'] ?? '', 'url'); }
            if ($type === 'wifi') { $this->field('SSID', 'payload[ssid]', $payload['ssid'] ?? ''); $this->field('Password', 'payload[password]', $payload['password'] ?? ''); echo '<tr><th>Encryption</th><td><select name="payload[encryption]"><option value="WPA" ' . selected($payload['encryption'] ?? 'WPA', 'WPA', false) . '>WPA/WPA2</option><option value="WEP" ' . selected($payload['encryption'] ?? '', 'WEP', false) . '>WEP</option><option value="NOPASS" ' . selected($payload['encryption'] ?? '', 'NOPASS', false) . '>None</option></select> <label><input type="checkbox" name="payload[hidden]" value="1" ' . checked(!empty($payload['hidden']), true, false) . ' /> Hidden network</label></td></tr>'; }
            if ($type === 'vcard') { foreach (['first_name'=>'First name','last_name'=>'Last name','organisation'=>'Organisation','job_title'=>'Job title','phone'=>'Phone','email'=>'Email','website'=>'Website','address'=>'Address'] as $key => $label) { $this->field($label, 'payload[' . $key . ']', $payload[$key] ?? '', in_array($key, ['email','website'], true) ? ($key === 'email' ? 'email' : 'url') : 'text'); } }
            if ($type === 'email') { $this->field('Recipient', 'payload[recipient]', $payload['recipient'] ?? '', 'email'); $this->field('Subject', 'payload[subject]', $payload['subject'] ?? ''); $this->textarea('Body', 'payload[body]', $payload['body'] ?? ''); }
            if ($type === 'phone') { $this->field('Phone number', 'payload[phone]', $payload['phone'] ?? ''); }
            if ($type === 'sms') { $this->field('Phone number', 'payload[phone]', $payload['phone'] ?? ''); $this->textarea('Message', 'payload[message]', $payload['message'] ?? ''); }
            if ($type === 'location') { $this->field('Latitude', 'payload[latitude]', $payload['latitude'] ?? ''); $this->field('Longitude', 'payload[longitude]', $payload['longitude'] ?? ''); $this->field('Label', 'payload[label]', $payload['label'] ?? ''); }
            if ($type === 'text') { $this->textarea('Text', 'payload[text]', $payload['text'] ?? ''); }
            echo '</tbody></table></div>';
        }
    }

    private function renderDynamicSettings(?QRCode $editing): void {
        $status = $editing ? $editing->status : 'active';
        echo '<tr><th>Status</th><td><select name="status"><option value="active" ' . selected($status, 'active', false) . '>Active</option><option value="paused" ' . selected($status, 'paused', false) . '>Paused</option></select></td></tr>';
        $this->field('Scheduled URL (legacy/simple)', 'scheduled_url', $editing ? (string) $editing->scheduledUrl : '', 'url');
        $this->field('Schedule start', 'scheduled_start_at', $editing ? DateTimeHelper::utcToLocalInput($editing->scheduledStartAt) : '', 'datetime-local');
        $this->field('Schedule end', 'scheduled_end_at', $editing ? DateTimeHelper::utcToLocalInput($editing->scheduledEndAt) : '', 'datetime-local');
        $this->field('Expiry date/time', 'expires_at', $editing ? DateTimeHelper::utcToLocalInput($editing->expiresAt) : '', 'datetime-local');
        $this->field('Fallback URL', 'fallback_url', $editing ? (string) $editing->fallbackUrl : '', 'url');
    }

    private function campaignField(?int $selected): void {
        echo '<tr><th><label for="qrbuzz-campaign">Campaign</label></th><td><select id="qrbuzz-campaign" name="campaign_id"><option value="0">Unassigned</option>';
        foreach ($this->campaigns->active() as $campaign) { echo '<option value="' . esc_attr($campaign->id) . '" ' . selected($selected, $campaign->id, false) . '>' . esc_html($campaign->name) . '</option>'; }
        echo '</select></td></tr>';
    }

    private function renderRules(QRCode $qrCode): void {
        $editingRule = isset($_GET['rule_id']) ? $this->rules->find(absint($_GET['rule_id'])) : null;
        if ($editingRule && $editingRule->qrId !== $qrCode->id) { $editingRule = null; }
        echo '<div class="qrbuzz-panel"><h3>Smart Destinations</h3><p class="description">Rules are evaluated by priority. The first matching active rule wins; otherwise QR Buzz uses the primary destination.</p>';
        echo '<table class="widefat striped"><thead><tr><th>Priority</th><th>Name</th><th>Status</th><th>Destination</th><th>Conditions</th><th>Actions</th></tr></thead><tbody>';
        $rules = $this->rules->forQrCode($qrCode->id);
        if (!$rules) { echo '<tr><td colspan="6">No Smart Destination rules yet.</td></tr>'; }
        foreach ($rules as $rule) { $this->ruleRow($qrCode, $rule); }
        echo '</tbody></table>';
        $this->ruleForm($qrCode, $editingRule);
        echo '</div>';
    }

    private function ruleRow(QRCode $qrCode, DestinationRule $rule): void {
        $edit = admin_url('admin.php?page=qr-buzz-codes&qrbuzz_action=edit&qr_id=' . $qrCode->id . '&rule_id=' . $rule->id);
        $toggle = wp_nonce_url(admin_url('admin.php?page=qr-buzz-codes&qrbuzz_action=' . ($rule->status === 'active' ? 'deactivate_rule' : 'activate_rule') . '&qr_id=' . $qrCode->id . '&rule_id=' . $rule->id), 'qrbuzz_rule_toggle_' . $rule->id);
        $delete = wp_nonce_url(admin_url('admin.php?page=qr-buzz-codes&qrbuzz_action=delete_rule&qr_id=' . $qrCode->id . '&rule_id=' . $rule->id), 'qrbuzz_rule_delete_' . $rule->id);
        echo '<tr><td>' . esc_html((string) $rule->priority) . '</td><td><strong>' . esc_html($rule->name) . '</strong></td><td>' . esc_html(ucfirst($rule->status)) . '</td><td><a href="' . esc_url($rule->destinationUrl) . '" target="_blank" rel="noopener noreferrer">' . esc_html($rule->destinationUrl) . '</a></td><td>' . esc_html($this->ruleConditionLabel($rule)) . '</td><td><a href="' . esc_url($edit) . '">Edit</a> | <a href="' . esc_url($toggle) . '">' . esc_html($rule->status === 'active' ? 'Deactivate' : 'Activate') . '</a> | <a href="' . esc_url($delete) . '" onclick="return confirm(&quot;Delete this rule?&quot;);">Delete</a></td></tr>';
    }

    private function ruleForm(QRCode $qrCode, ?DestinationRule $rule): void {
        echo '<h4>' . esc_html($rule ? 'Edit Rule' : 'Add Rule') . '</h4><form method="post" action="' . esc_url(admin_url('admin.php?page=qr-buzz-codes')) . '">';
        wp_nonce_field('qrbuzz_save_rule', 'qrbuzz_rule_nonce');
        echo '<input type="hidden" name="qrbuzz_action" value="save_rule" /><input type="hidden" name="qr_id" value="' . esc_attr($qrCode->id) . '" /><input type="hidden" name="rule_id" value="' . esc_attr($rule ? $rule->id : 0) . '" />';
        echo '<div class="qrbuzz-rule-grid">';
        echo '<p><label>Name<br><input class="regular-text" name="rule_name" value="' . esc_attr($rule ? $rule->name : '') . '" /></label></p>';
        echo '<p><label>Priority<br><input type="number" min="1" name="priority" value="' . esc_attr($rule ? $rule->priority : 10) . '" /></label></p>';
        echo '<p><label>Status<br><select name="rule_status"><option value="active" ' . selected($rule ? $rule->status : 'active', 'active', false) . '>Active</option><option value="inactive" ' . selected($rule ? $rule->status : '', 'inactive', false) . '>Inactive</option></select></label></p>';
        echo '<p><label>Destination URL<br><input class="regular-text" type="url" name="rule_destination_url" value="' . esc_attr($rule ? $rule->destinationUrl : '') . '" /></label></p>';
        echo '<p><label>Starts<br><input type="datetime-local" name="rule_starts_at" value="' . esc_attr($rule ? DateTimeHelper::utcToLocalInput($rule->startsAt) : '') . '" /></label></p>';
        echo '<p><label>Ends<br><input type="datetime-local" name="rule_ends_at" value="' . esc_attr($rule ? DateTimeHelper::utcToLocalInput($rule->endsAt) : '') . '" /></label></p>';
        echo '<p><label>Start time<br><input type="time" name="rule_time_start" value="' . esc_attr($rule ? (string) $rule->timeStart : '') . '" /></label></p>';
        echo '<p><label>End time<br><input type="time" name="rule_time_end" value="' . esc_attr($rule ? (string) $rule->timeEnd : '') . '" /></label></p>';
        echo '</div><fieldset><legend>Days of week</legend>';
        $days = $rule ? $rule->dayNumbers() : [];
        foreach ([1=>'Monday',2=>'Tuesday',3=>'Wednesday',4=>'Thursday',5=>'Friday',6=>'Saturday',7=>'Sunday'] as $day => $label) { echo '<label style="margin-right:12px"><input type="checkbox" name="rule_days[]" value="' . esc_attr($day) . '" ' . checked(in_array($day, $days, true), true, false) . ' /> ' . esc_html($label) . '</label>'; }
        echo '</fieldset>';
        submit_button($rule ? 'Update Rule' : 'Add Rule', 'secondary');
        echo '</form>';
    }

    private function saveRule(): void {
        check_admin_referer('qrbuzz_save_rule', 'qrbuzz_rule_nonce');
        $qrId = isset($_POST['qr_id']) ? absint($_POST['qr_id']) : 0;
        $ruleId = isset($_POST['rule_id']) ? absint($_POST['rule_id']) : 0;
        $destination = isset($_POST['rule_destination_url']) ? esc_url_raw(wp_unslash($_POST['rule_destination_url'])) : '';
        if (!$qrId || !$destination || !wp_http_validate_url($destination)) { $this->redirectWithNotice('invalid_rule', $qrId); }
        $data = ['name' => isset($_POST['rule_name']) ? sanitize_text_field(wp_unslash($_POST['rule_name'])) : 'Smart destination', 'priority' => isset($_POST['priority']) ? absint($_POST['priority']) : 10, 'status' => isset($_POST['rule_status']) ? sanitize_key(wp_unslash($_POST['rule_status'])) : 'active', 'destination_url' => $destination, 'starts_at' => $this->optionalDateTime('rule_starts_at'), 'ends_at' => $this->optionalDateTime('rule_ends_at'), 'days_of_week' => isset($_POST['rule_days']) && is_array($_POST['rule_days']) ? array_map('absint', wp_unslash($_POST['rule_days'])) : [], 'time_start' => isset($_POST['rule_time_start']) ? sanitize_text_field(wp_unslash($_POST['rule_time_start'])) : '', 'time_end' => isset($_POST['rule_time_end']) ? sanitize_text_field(wp_unslash($_POST['rule_time_end'])) : ''];
        if ($ruleId > 0) { $this->rules->update($ruleId, $data, get_current_user_id()); $this->redirectWithNotice('rule_updated', $qrId); }
        $this->rules->create($qrId, $data, get_current_user_id());
        $this->redirectWithNotice('rule_created', $qrId);
    }

    private function renderRedirectPreview(QRCode $qrCode): void {
        $testValue = isset($_GET['qrbuzz_test_time']) ? sanitize_text_field(wp_unslash($_GET['qrbuzz_test_time'])) : '';
        $testUtc = DateTimeHelper::localInputToUtc($testValue);
        $timestamp = $testUtc ? DateTimeHelper::utcTimestamp($testUtc) : null;
        $resolution = $this->resolver->resolve($qrCode, $timestamp);
        $target = $resolution->shouldRedirect && $resolution->destinationUrl ? $resolution->destinationUrl : $resolution->message;
        echo '<div class="qrbuzz-panel"><h3>Destination Simulator</h3><form method="get"><input type="hidden" name="page" value="qr-buzz-codes" /><input type="hidden" name="qrbuzz_action" value="edit" /><input type="hidden" name="qr_id" value="' . esc_attr($qrCode->id) . '" /><label>Test date/time <input type="datetime-local" name="qrbuzz_test_time" value="' . esc_attr($testValue) . '" /></label> '; submit_button('Simulate', 'secondary', '', false); echo '</form>';
        echo '<div class="qrbuzz-preview"><div><strong>Current WordPress time</strong><br>' . esc_html(DateTimeHelper::nowLocalDisplay()) . '</div><div><strong>Test time</strong><br>' . esc_html($testUtc ? DateTimeHelper::utcToDisplay($testUtc) : 'Now') . '</div><div><strong>QR status</strong><br>' . esc_html($qrCode->effectiveStatus($timestamp)) . '</div><div><strong>Matched rule</strong><br>' . esc_html($resolution->matchedRuleName ?: '-') . '</div><div><strong>Resolved destination</strong><br>' . esc_html($target ?: '-') . '</div><div><strong>Reason</strong><br>' . esc_html($this->label($resolution->reason)) . '</div><div><strong>Primary fallback</strong><br>' . esc_html($qrCode->destinationUrl ?: '-') . '</div><div><strong>Expiry</strong><br>' . esc_html(DateTimeHelper::utcToDisplay($qrCode->expiresAt)) . '</div></div></div>';
    }

    private function ruleConditionLabel(DestinationRule $rule): string {
        $parts = [];
        if ($rule->startsAt || $rule->endsAt) { $parts[] = DateTimeHelper::utcToDisplay($rule->startsAt) . ' to ' . DateTimeHelper::utcToDisplay($rule->endsAt); }
        if ($rule->daysOfWeek) { $parts[] = 'Days: ' . $rule->daysOfWeek; }
        if ($rule->timeStart || $rule->timeEnd) { $parts[] = 'Time: ' . ($rule->timeStart ?: '00:00') . ' to ' . ($rule->timeEnd ?: '23:59'); }
        return $parts ? implode('; ', $parts) : 'Always active';
    }

    private function field(string $label, string $name, string $value, string $type = 'text'): void { echo '<tr><th><label>' . esc_html($label) . '</label></th><td><input class="regular-text" type="' . esc_attr($type) . '" name="' . esc_attr($name) . '" value="' . esc_attr($value) . '" /></td></tr>'; }
    private function textarea(string $label, string $name, string $value): void { echo '<tr><th><label>' . esc_html($label) . '</label></th><td><textarea class="large-text" rows="4" name="' . esc_attr($name) . '">' . esc_textarea($value) . '</textarea></td></tr>'; }

    private function payloadDataFromPost(string $type): array {
        $payload = isset($_POST['payload']) && is_array($_POST['payload']) ? wp_unslash($_POST['payload']) : [];
        $clean = [];
        foreach ($payload as $key => $value) { $clean[sanitize_key((string) $key)] = is_scalar($value) ? sanitize_textarea_field((string) $value) : ''; }
        if ($type === 'static_url' && isset($clean['url'])) { $clean['url'] = esc_url_raw($clean['url']); }
        if ($type === 'dynamic_url' && isset($clean['destination_url'])) { $clean['destination_url'] = esc_url_raw($clean['destination_url']); }
        if ($type === 'vcard' && isset($clean['website'])) { $clean['website'] = esc_url_raw($clean['website']); }
        if ($type === 'email' && isset($clean['recipient'])) { $clean['recipient'] = sanitize_email($clean['recipient']); }
        $clean['hidden'] = !empty($payload['hidden']) ? '1' : '';
        return $clean;
    }

    private function renderRecentActivity(array $scans): void { echo '<div class="qrbuzz-panel"><h2>Recent Activity</h2>'; if (!$scans) { echo '<p>No recent scans yet.</p></div>'; return; } echo '<ul>'; foreach ($scans as $scan) { echo '<li><strong>' . esc_html($scan->qr_name) . '</strong> scanned ' . esc_html(human_time_diff(strtotime((string) $scan->scanned_at), current_time('timestamp'))) . ' ago</li>'; } echo '</ul></div>'; }
    private function renderHistory(QRCode $qrCode): void { $history = $this->repository->destinationHistory($qrCode->id); echo '<div class="qrbuzz-panel"><h3>Destination & Rule History</h3><table class="widefat striped"><thead><tr><th>Changed</th><th>Type</th><th>Previous</th><th>New</th><th>User</th></tr></thead><tbody>'; if (!$history) { echo '<tr><td colspan="5">No destination changes recorded yet.</td></tr>'; } foreach ($history as $row) { echo '<tr><td>' . esc_html($this->formatDate((string) $row->changed_at)) . '</td><td>' . esc_html($this->label((string) $row->change_type)) . '</td><td>' . esc_html($row->previous_value ?: '-') . '</td><td>' . esc_html($row->new_value ?: '-') . '</td><td>' . esc_html($row->user_name ?: '-') . '</td></tr>'; } echo '</tbody></table></div>'; }
    private function deleteQrCode(int $id): void { check_admin_referer('qrbuzz_delete_qr_' . $id); $this->repository->delete($id); $this->redirectWithNotice('deleted'); }
    private function toggleQrCode(int $id, bool $active): void { check_admin_referer('qrbuzz_toggle_qr_' . $id); $this->repository->setActive($id, $active); $this->redirectWithNotice($active ? 'activated' : 'deactivated'); }
    private function deleteRule(int $id): void { check_admin_referer('qrbuzz_rule_delete_' . $id); $rule = $this->rules->find($id); $qrId = $rule ? $rule->qrId : 0; $this->rules->delete($id, get_current_user_id()); $this->redirectWithNotice('rule_deleted', $qrId); }
    private function toggleRule(int $id, bool $active): void { check_admin_referer('qrbuzz_rule_toggle_' . $id); $rule = $this->rules->find($id); $qrId = $rule ? $rule->qrId : 0; $this->rules->setStatus($id, $active ? 'active' : 'inactive', get_current_user_id()); $this->redirectWithNotice($active ? 'rule_activated' : 'rule_deactivated', $qrId); }
    private function editingQrCode(): ?QRCode { return isset($_GET['qrbuzz_action'], $_GET['qr_id']) && $_GET['qrbuzz_action'] === 'edit' ? $this->repository->find(absint($_GET['qr_id'])) : null; }
    private function notice(): void { $notice = isset($_GET['qrbuzz_notice']) ? sanitize_key(wp_unslash($_GET['qrbuzz_notice'])) : ''; $messages = ['created'=>['success','QR code created.'],'dynamic_updated'=>['success','QR code updated.'],'deleted'=>['success','QR code deleted.'],'activated'=>['success','QR code activated.'],'deactivated'=>['success','QR code paused.'],'rule_created'=>['success','Smart Destination rule created.'],'rule_updated'=>['success','Smart Destination rule updated.'],'rule_deleted'=>['success','Smart Destination rule deleted.'],'rule_activated'=>['success','Smart Destination rule activated.'],'rule_deactivated'=>['success','Smart Destination rule deactivated.'],'invalid'=>['error','Please enter the required QR details.'],'invalid_smart'=>['error','Please check the smart destination URLs and dates.'],'invalid_schedule'=>['error','A scheduled destination needs a URL, start time, and end time, with the end after the start.'],'invalid_rule'=>['error','Please enter a valid rule destination URL.']]; if (!isset($messages[$notice])) { return; } [$type,$message] = $messages[$notice]; echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>'; }
    private function guard(): void { if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to access QR Buzz.', 'qr-buzz')); } }
    private function optionalUrl(string $field): ?string { $url = isset($_POST[$field]) ? esc_url_raw(wp_unslash($_POST[$field])) : ''; return trim($url) === '' ? null : $url; }
    private function optionalUrlIsValid(string $field): bool { $url = isset($_POST[$field]) ? trim((string) wp_unslash($_POST[$field])) : ''; return $url === '' || (bool) wp_http_validate_url(esc_url_raw($url)); }
    private function optionalDateTime(string $field): ?string { $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : ''; return DateTimeHelper::localInputToUtc($value); }
    private function optionalDateTimeIsValid(string $field): bool { $value = isset($_POST[$field]) ? sanitize_text_field(wp_unslash($_POST[$field])) : ''; return trim($value) === '' || DateTimeHelper::localInputToUtc($value) !== null; }
    private function scheduleIsValid(?string $scheduledUrl, ?string $scheduledStartAt, ?string $scheduledEndAt): bool { $hasSchedule = $scheduledUrl || $scheduledStartAt || $scheduledEndAt; if (!$hasSchedule) { return true; } if (!$scheduledUrl || !$scheduledStartAt || !$scheduledEndAt) { return false; } return strtotime($scheduledEndAt . ' UTC') > strtotime($scheduledStartAt . ' UTC'); }
    private function isQrBuzzPage(): bool { $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : ''; return in_array($page, ['qr-buzz', 'qr-buzz-codes', 'qr-buzz-create', 'qr-buzz-settings'], true); }
    private function formatDate(?string $date): string { return $date ? mysql2date(get_option('date_format') . ' ' . get_option('time_format'), $date) : '-'; }
    private function label(string $value): string { return ucwords(str_replace('_', ' ', $value)); }
    private function redirectWithNotice(string $notice, int $qrId = 0): void { $url = admin_url('admin.php?page=qr-buzz-codes'); if ($qrId > 0) { $url = add_query_arg(['qrbuzz_action' => 'edit', 'qr_id' => $qrId], $url); } wp_safe_redirect(add_query_arg('qrbuzz_notice', $notice, $url)); exit; }
}
