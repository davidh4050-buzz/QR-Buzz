<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\CampaignRepository;

class CampaignsPage {

    private CampaignRepository $campaigns;

    public function __construct(?CampaignRepository $campaigns = null) {
        $this->campaigns = $campaigns ?: new CampaignRepository();
    }

    public function init(): void {
        add_action('admin_init', [$this, 'handleRequest']);
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_enqueue_scripts', [$this, 'assets']);
    }

    public function menu(): void {
        add_submenu_page('qr-buzz', 'QR Buzz Campaigns', 'Campaigns', 'manage_options', 'qr-buzz-campaigns', [$this, 'render']);
    }

    public function assets(string $hook): void {
        if ($hook !== 'qr-buzz_page_qr-buzz-campaigns') { return; }
        wp_register_style('qrbuzz-campaigns', false, [], QR_BUZZ_VERSION);
        wp_enqueue_style('qrbuzz-campaigns');
        wp_add_inline_style('qrbuzz-campaigns', '.qrbuzz-panel{background:#fff;border:1px solid #c3c4c7;padding:16px;margin:16px 0}.qrbuzz-actions{display:flex;gap:8px;flex-wrap:wrap}.qrbuzz-status{font-weight:600}.qrbuzz-status-archived{color:#646970}.qrbuzz-status-active{color:#008a20}');
    }

    public function handleRequest(): void {
        if (!$this->isPage() || !current_user_can('manage_options')) { return; }
        $action = isset($_REQUEST['qrbuzz_campaign_action']) ? sanitize_key(wp_unslash($_REQUEST['qrbuzz_campaign_action'])) : '';
        if ($action === 'save' && $_SERVER['REQUEST_METHOD'] === 'POST') { $this->save(); }
        if ($action === 'archive' && isset($_GET['campaign_id'])) { $this->status(absint($_GET['campaign_id']), 'archived'); }
        if ($action === 'unarchive' && isset($_GET['campaign_id'])) { $this->status(absint($_GET['campaign_id']), 'active'); }
        if ($action === 'delete' && isset($_GET['campaign_id'])) { $this->delete(absint($_GET['campaign_id'])); }
    }

    public function render(): void {
        $this->guard();
        $editing = $this->editing();
        echo '<div class="wrap"><h1>Campaigns</h1>';
        $this->notice();
        $this->form($editing);
        $this->list();
        echo '</div>';
    }

    private function form($editing): void {
        echo '<div class="qrbuzz-panel"><h2>' . esc_html($editing ? 'Edit Campaign' : 'Create Campaign') . '</h2><form method="post" action="' . esc_url(admin_url('admin.php?page=qr-buzz-campaigns')) . '">';
        wp_nonce_field('qrbuzz_save_campaign', 'qrbuzz_campaign_nonce');
        echo '<input type="hidden" name="qrbuzz_campaign_action" value="save" /><input type="hidden" name="campaign_id" value="' . esc_attr($editing ? $editing->id : 0) . '" />';
        echo '<table class="form-table"><tbody>';
        echo '<tr><th><label for="qrbuzz-campaign-name">Name</label></th><td><input id="qrbuzz-campaign-name" class="regular-text" name="name" value="' . esc_attr($editing ? $editing->name : '') . '" required /></td></tr>';
        echo '<tr><th><label for="qrbuzz-campaign-description">Description</label></th><td><textarea id="qrbuzz-campaign-description" class="large-text" rows="3" name="description">' . esc_textarea($editing ? $editing->description : '') . '</textarea></td></tr>';
        echo '<tr><th>Status</th><td><select name="status"><option value="active" ' . selected($editing ? $editing->status : 'active', 'active', false) . '>Active</option><option value="archived" ' . selected($editing ? $editing->status : '', 'archived', false) . '>Archived</option></select></td></tr>';
        echo '</tbody></table>';
        submit_button($editing ? 'Update Campaign' : 'Create Campaign');
        if ($editing) { echo '<p><a href="' . esc_url(admin_url('admin.php?page=qr-buzz-campaigns')) . '">Cancel edit</a></p>'; }
        echo '</form></div>';
    }

    private function list(): void {
        echo '<h2>Campaign List</h2><table class="widefat striped"><thead><tr><th>Name</th><th>Status</th><th>QR Codes</th><th>Dynamic</th><th>Static</th><th>Scans</th><th>Actions</th></tr></thead><tbody>';
        $campaigns = $this->campaigns->all();
        if (!$campaigns) { echo '<tr><td colspan="7">No campaigns yet.</td></tr>'; }
        foreach ($campaigns as $campaign) {
            $edit = admin_url('admin.php?page=qr-buzz-campaigns&qrbuzz_campaign_action=edit&campaign_id=' . $campaign->id);
            $analytics = admin_url('admin.php?page=qr-buzz-analytics&campaign_id=' . $campaign->id);
            $toggle = wp_nonce_url(admin_url('admin.php?page=qr-buzz-campaigns&qrbuzz_campaign_action=' . ($campaign->status === 'archived' ? 'unarchive' : 'archive') . '&campaign_id=' . $campaign->id), 'qrbuzz_campaign_status_' . $campaign->id);
            $delete = wp_nonce_url(admin_url('admin.php?page=qr-buzz-campaigns&qrbuzz_campaign_action=delete&campaign_id=' . $campaign->id), 'qrbuzz_campaign_delete_' . $campaign->id);
            echo '<tr><td><strong>' . esc_html($campaign->name) . '</strong><br><code>' . esc_html($campaign->slug) . '</code></td><td><span class="qrbuzz-status qrbuzz-status-' . esc_attr($campaign->status) . '">' . esc_html(ucfirst($campaign->status)) . '</span></td><td>' . esc_html((string) $campaign->qrCount) . '</td><td>' . esc_html((string) $campaign->dynamicCount) . '</td><td>' . esc_html((string) $campaign->staticCount) . '</td><td>' . esc_html((string) $campaign->scanCount) . '</td><td class="qrbuzz-actions"><a href="' . esc_url($edit) . '">Edit</a><a href="' . esc_url($analytics) . '">Analytics</a><a href="' . esc_url($toggle) . '">' . esc_html($campaign->status === 'archived' ? 'Unarchive' : 'Archive') . '</a>';
            if ($campaign->qrCount === 0) { echo '<a href="' . esc_url($delete) . '" onclick="return confirm(&quot;Delete this campaign?&quot;);">Delete</a>'; }
            echo '</td></tr>';
        }
        echo '</tbody></table>';
    }

    private function save(): void {
        check_admin_referer('qrbuzz_save_campaign', 'qrbuzz_campaign_nonce');
        $id = isset($_POST['campaign_id']) ? absint($_POST['campaign_id']) : 0;
        $name = isset($_POST['name']) ? sanitize_text_field(wp_unslash($_POST['name'])) : '';
        $description = isset($_POST['description']) ? sanitize_textarea_field(wp_unslash($_POST['description'])) : '';
        $status = isset($_POST['status']) ? sanitize_key(wp_unslash($_POST['status'])) : 'active';
        if ($name === '') { $this->redirect('invalid'); }
        if ($id > 0) { $this->campaigns->update($id, $name, $description, $status); $this->redirect('updated'); }
        $this->campaigns->create($name, $description);
        $this->redirect('created');
    }

    private function status(int $id, string $status): void {
        check_admin_referer('qrbuzz_campaign_status_' . $id);
        $this->campaigns->setStatus($id, $status);
        $this->redirect($status === 'archived' ? 'archived' : 'unarchived');
    }

    private function delete(int $id): void {
        check_admin_referer('qrbuzz_campaign_delete_' . $id);
        $this->redirect($this->campaigns->delete($id) ? 'deleted' : 'delete_blocked');
    }

    private function editing() {
        return isset($_GET['qrbuzz_campaign_action'], $_GET['campaign_id']) && $_GET['qrbuzz_campaign_action'] === 'edit' ? $this->campaigns->find(absint($_GET['campaign_id'])) : null;
    }

    private function notice(): void {
        $notice = isset($_GET['qrbuzz_notice']) ? sanitize_key(wp_unslash($_GET['qrbuzz_notice'])) : '';
        $messages = ['created'=>['success','Campaign created.'],'updated'=>['success','Campaign updated.'],'archived'=>['success','Campaign archived.'],'unarchived'=>['success','Campaign restored.'],'deleted'=>['success','Campaign deleted.'],'delete_blocked'=>['error','Only empty campaigns can be deleted.'],'invalid'=>['error','Please enter a campaign name.']];
        if (!isset($messages[$notice])) { return; }
        [$type,$message] = $messages[$notice];
        echo '<div class="notice notice-' . esc_attr($type) . ' is-dismissible"><p>' . esc_html($message) . '</p></div>';
    }

    private function redirect(string $notice): void { wp_safe_redirect(add_query_arg('qrbuzz_notice', $notice, admin_url('admin.php?page=qr-buzz-campaigns'))); exit; }
    private function guard(): void { if (!current_user_can('manage_options')) { wp_die(esc_html__('You do not have permission to access QR Buzz campaigns.', 'qr-buzz')); } }
    private function isPage(): bool { return isset($_REQUEST['page']) && sanitize_key(wp_unslash($_REQUEST['page'])) === 'qr-buzz-campaigns'; }
}
