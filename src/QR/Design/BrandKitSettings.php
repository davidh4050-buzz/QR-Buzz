<?php
namespace QRBuzz\QR\Design;

use QRBuzz\Workspace\WorkspaceService;

class BrandKitSettings {

    public const LEGACY_OPTION = 'qrbuzz_brand_kit';

    private QRThemeRegistry $themes;
    private WorkspaceService $workspaces;

    public function __construct(?QRThemeRegistry $themes = null, ?WorkspaceService $workspaces = null) {
        $this->themes = $themes ?: new QRThemeRegistry();
        $this->workspaces = $workspaces ?: new WorkspaceService();
    }

    public function get(): array {
        $saved = get_option($this->optionName(), null);
        if (!is_array($saved)) {
            $saved = get_option(self::LEGACY_OPTION, []);
        }
        $saved = is_array($saved) ? $saved : [];
        return array_merge($this->defaults(), $saved);
    }

    public function defaults(): array {
        return ['brand_name' => '', 'primary_color' => '#0f4c81', 'secondary_color' => '#16a085', 'foreground_color' => '#000000', 'background_color' => '#ffffff', 'default_theme' => 'classic', 'default_logo_attachment_id' => 0, 'default_error_correction' => 'H'];
    }

    public function designDefaults(bool $includeLogo = false): QRDesignSettings {
        $settings = $this->get();
        $theme = $this->themes->designSettings((string) $settings['default_theme']);
        return new QRDesignSettings(array_merge($theme->toArray(), ['foreground_color' => $settings['foreground_color'], 'background_color' => $settings['background_color'], 'error_correction' => $settings['default_error_correction'], 'logo_attachment_id' => $includeLogo ? absint($settings['default_logo_attachment_id']) : 0]));
    }

    public function saveFromPost(array $post): void {
        $settings = [
            'brand_name' => isset($post['brand_name']) ? sanitize_text_field(wp_unslash($post['brand_name'])) : '',
            'primary_color' => QRDesignSettings::sanitizeColor(isset($post['primary_color']) ? sanitize_text_field(wp_unslash($post['primary_color'])) : '', '#0f4c81'),
            'secondary_color' => QRDesignSettings::sanitizeColor(isset($post['secondary_color']) ? sanitize_text_field(wp_unslash($post['secondary_color'])) : '', '#16a085'),
            'foreground_color' => QRDesignSettings::sanitizeColor(isset($post['foreground_color']) ? sanitize_text_field(wp_unslash($post['foreground_color'])) : '', '#000000'),
            'background_color' => QRDesignSettings::sanitizeColor(isset($post['background_color']) ? sanitize_text_field(wp_unslash($post['background_color'])) : '', '#ffffff'),
            'default_theme' => QRDesignSettings::sanitizeTheme(isset($post['default_theme']) ? sanitize_key(wp_unslash($post['default_theme'])) : 'classic'),
            'default_logo_attachment_id' => $this->safeImageAttachmentId(isset($post['default_logo_attachment_id']) ? absint($post['default_logo_attachment_id']) : 0),
            'default_error_correction' => QRDesignSettings::sanitizeErrorCorrection(isset($post['default_error_correction']) ? sanitize_text_field(wp_unslash($post['default_error_correction'])) : 'H'),
        ];
        update_option($this->optionName(), $settings, false);
    }

    public function safeImageAttachmentId(int $attachmentId): int {
        if ($attachmentId <= 0) { return 0; }
        return wp_attachment_is_image($attachmentId) ? $attachmentId : 0;
    }

    public function optionName(): string {
        return 'qrbuzz_brand_kit_' . $this->workspaces->id();
    }
}