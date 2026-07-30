<?php
namespace QRBuzz\Admin;

use QRBuzz\Membership\EntitlementService;

class PlanGuard {

    private EntitlementService $entitlements;

    public function __construct(?EntitlementService $entitlements = null) {
        $this->entitlements = $entitlements ?: new EntitlementService();
    }

    public function init(): void {
        add_action('admin_init', [$this, 'handleRequest'], 0);
        add_action('admin_enqueue_scripts', [$this, 'assets'], 20);
    }

    public function handleRequest(): void {
        if (!current_user_can('manage_options') || !$this->isQrBuzzPage()) { return; }
        $action = isset($_REQUEST['qrbuzz_action']) ? sanitize_key(wp_unslash($_REQUEST['qrbuzz_action'])) : '';

        if ($action === 'save' && $this->isPost()) { $this->filterQrSave(); }
        if ($action === 'save_brand_kit' && $this->isPost()) { $this->filterBrandKitSave(); }
        if ($action === 'save_rule' && $this->isPost() && !$this->entitlements->allows('smart_destinations')) { $this->blocked('Smart Destinations are not available on the current plan.'); }
        if (in_array($action, ['delete_rule', 'activate_rule', 'deactivate_rule'], true) && !$this->entitlements->allows('smart_destinations')) { $this->blocked('Smart Destination rule actions are not available on the current plan.'); }
    }

    public function assets(string $hook): void {
        if (!in_array($hook, ['qr-buzz_page_qr-buzz-codes', 'qr-buzz_page_qr-buzz-create', 'qr-buzz_page_qr-buzz-settings'], true)) { return; }
        $locked = [
            'advancedBranding' => !$this->entitlements->allows('advanced_branding'),
            'logoEmbedding' => !$this->entitlements->allows('logo_embedding'),
            'smartDestinations' => !$this->entitlements->allows('smart_destinations'),
        ];
        if (!$locked['advancedBranding'] && !$locked['logoEmbedding'] && !$locked['smartDestinations']) { return; }

        wp_register_style('qrbuzz-plan-guard', false, [], QR_BUZZ_VERSION);
        wp_enqueue_style('qrbuzz-plan-guard');
        wp_add_inline_style('qrbuzz-plan-guard', '.qrbuzz-plan-locked{border-left:4px solid #d63638;background:#fff8f8}.qrbuzz-plan-locked input,.qrbuzz-plan-locked select,.qrbuzz-plan-locked textarea,.qrbuzz-plan-locked button{pointer-events:none;opacity:.55}.qrbuzz-plan-lock-note{margin:8px 0 0;color:#8a2424;font-weight:600}.qrbuzz-plan-hidden{display:none!important}');

        wp_register_script('qrbuzz-plan-guard', '', [], QR_BUZZ_VERSION, true);
        wp_enqueue_script('qrbuzz-plan-guard');
        wp_add_inline_script('qrbuzz-plan-guard', 'window.QRBuzzPlanLocks=' . wp_json_encode($locked) . ';' . $this->script());
    }

    private function filterQrSave(): void {
        if (!$this->entitlements->allows('smart_destinations')) {
            $_POST['scheduled_url'] = '';
            $_POST['scheduled_start_at'] = '';
            $_POST['scheduled_end_at'] = '';
            $_POST['expires_at'] = '';
            $_POST['fallback_url'] = '';
        }

        if (!$this->entitlements->allows('advanced_branding')) {
            $_POST['theme'] = 'classic';
            $_POST['foreground_color'] = '#000000';
            $_POST['background_color'] = '#ffffff';
            $_POST['transparent_background'] = '';
            $_POST['error_correction'] = 'M';
            $_POST['margin'] = '10';
            $_POST['dot_style'] = 'square';
            $_POST['finder_style'] = 'square';
            $_POST['finder_dot_style'] = 'square';
            $_POST['finder_color'] = '';
            $_POST['caption'] = '';
            $_POST['caption_font_size'] = '16';
            $_POST['caption_font_color'] = '#000000';
        }

        if (!$this->entitlements->allows('logo_embedding')) {
            $_POST['logo_attachment_id'] = '0';
            $_POST['logo_size'] = '20';
        }
    }

    private function filterBrandKitSave(): void {
        if (!$this->entitlements->allows('advanced_branding')) {
            $_POST['primary_color'] = '#0f766e';
            $_POST['secondary_color'] = '#1f2937';
            $_POST['foreground_color'] = '#000000';
            $_POST['background_color'] = '#ffffff';
            $_POST['default_theme'] = 'classic';
            $_POST['default_error_correction'] = 'M';
        }

        if (!$this->entitlements->allows('logo_embedding')) {
            $_POST['default_logo_attachment_id'] = '0';
        }
    }

    private function script(): string {
        return <<<'JS'
document.addEventListener('DOMContentLoaded', function() {
    var locks = window.QRBuzzPlanLocks || {};

    function note(text) {
        var p = document.createElement('p');
        p.className = 'qrbuzz-plan-lock-note';
        p.textContent = text;
        return p;
    }

    function panelByHeading(text) {
        var headings = document.querySelectorAll('.qrbuzz-panel h2,.qrbuzz-panel h3,.qrbuzz-panel h4');
        for (var i = 0; i < headings.length; i++) {
            if (headings[i].textContent.trim() === text) {
                return headings[i].closest('.qrbuzz-panel');
            }
        }
        return null;
    }

    function rowByInput(name) {
        var field = document.querySelector('[name="' + name + '"]');
        return field ? field.closest('tr,p,fieldset') : null;
    }

    function lockPanel(panel, message) {
        if (!panel) { return; }
        panel.classList.add('qrbuzz-plan-locked');
        if (!panel.querySelector('.qrbuzz-plan-lock-note')) {
            panel.insertBefore(note(message), panel.children[1] || null);
        }
    }

    function hideRow(name) {
        var row = rowByInput(name);
        if (row) { row.classList.add('qrbuzz-plan-hidden'); }
    }

    if (locks.advancedBranding) {
        lockPanel(panelByHeading('Design'), 'Advanced branding is not available on the current plan. QR codes will use the Classic design.');
        ['dot_style', 'finder_style', 'finder_dot_style', 'finder_color', 'caption', 'caption_font_size', 'caption_font_color'].forEach(hideRow);
    }

    if (locks.logoEmbedding) {
        hideRow('logo_attachment_id');
        hideRow('default_logo_attachment_id');
        var logoSize = rowByInput('logo_size');
        if (logoSize) { logoSize.classList.add('qrbuzz-plan-hidden'); }
    }

    if (locks.smartDestinations) {
        ['scheduled_url', 'scheduled_start_at', 'scheduled_end_at', 'expires_at', 'fallback_url'].forEach(hideRow);
        lockPanel(panelByHeading('Smart Destinations'), 'Smart Destinations are not available on the current plan.');
        var ruleFormAction = document.querySelector('[name="qrbuzz_action"][value="save_rule"]');
        if (ruleFormAction) {
            var form = ruleFormAction.closest('form');
            if (form) { form.classList.add('qrbuzz-plan-hidden'); }
        }
    }
});
JS;
    }

    private function isQrBuzzPage(): bool {
        $page = isset($_REQUEST['page']) ? sanitize_key(wp_unslash($_REQUEST['page'])) : '';
        return in_array($page, ['qr-buzz', 'qr-buzz-codes', 'qr-buzz-create', 'qr-buzz-settings'], true);
    }

    private function isPost(): bool { return isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'; }
    private function blocked(string $message): void { wp_die(esc_html($message), esc_html__('QR Buzz plan limit', 'qr-buzz'), ['response' => 403]); }
}
