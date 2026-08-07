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
        return ['brand_name' => '', 'primary_color' => '#219b8b', 'secondary_color' => '#f2a100', 'foreground_color' => '#000000', 'background_color' => '#ffffff', 'default_theme' => 'classic', 'default_logo_attachment_id' => 0, 'default_error_correction' => 'H', 'margin' => 12, 'logo_size' => 20, 'dot_style' => 'square', 'finder_style' => 'square', 'finder_dot_style' => 'square', 'finder_color' => '', 'caption_font_family' => 'arial', 'caption_font_size' => 16, 'caption_font_color' => '#000000'];
    }

    public function designDefaults(bool $includeLogo = false): QRDesignSettings {
        $settings = $this->get();
        $theme = $this->themes->designSettings((string) $settings['default_theme']);
        return new QRDesignSettings(array_merge($theme->toArray(), ['foreground_color' => $settings['foreground_color'], 'background_color' => $settings['background_color'], 'error_correction' => $settings['default_error_correction'], 'logo_attachment_id' => $includeLogo ? absint($settings['default_logo_attachment_id']) : 0, 'margin' => $settings['margin'], 'logo_size' => $settings['logo_size'], 'dot_style' => $settings['dot_style'], 'finder_style' => $settings['finder_style'], 'finder_dot_style' => $settings['finder_dot_style'], 'finder_color' => $settings['finder_color'], 'caption_font_family' => $settings['caption_font_family'], 'caption_font_size' => $settings['caption_font_size'], 'caption_font_color' => $settings['caption_font_color']]));
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
            'margin' => QRDesignSettings::sanitizeMargin($post['margin'] ?? 12),
            'logo_size' => QRDesignSettings::sanitizeLogoSize($post['logo_size'] ?? 20),
            'dot_style' => QRDesignSettings::sanitizeDotStyle(isset($post['dot_style']) ? sanitize_key(wp_unslash($post['dot_style'])) : 'square'),
            'finder_style' => QRDesignSettings::sanitizeFinderStyle(isset($post['finder_style']) ? sanitize_key(wp_unslash($post['finder_style'])) : 'square'),
            'finder_dot_style' => QRDesignSettings::sanitizeFinderDotStyle(isset($post['finder_dot_style']) ? sanitize_key(wp_unslash($post['finder_dot_style'])) : 'square'),
            'finder_color' => QRDesignSettings::sanitizeOptionalColor(isset($post['finder_color']) ? sanitize_text_field(wp_unslash($post['finder_color'])) : '') ?: '',
            'caption_font_family' => QRDesignSettings::sanitizeCaptionFontFamily(isset($post['caption_font_family']) ? sanitize_key(wp_unslash($post['caption_font_family'])) : 'arial'),
            'caption_font_size' => QRDesignSettings::sanitizeCaptionFontSize($post['caption_font_size'] ?? 16),
            'caption_font_color' => QRDesignSettings::sanitizeColor(isset($post['caption_font_color']) ? sanitize_text_field(wp_unslash($post['caption_font_color'])) : '', '#000000'),
        ];
        update_option($this->optionName(), $settings, false);
    }

    public function saveFromDesign(QRDesignSettings $design, string $brandName = ''): void {
        $current = $this->get();
        update_option($this->optionName(), array_merge($current, [
            'brand_name' => $brandName !== '' ? sanitize_text_field($brandName) : $current['brand_name'],
            'foreground_color' => $design->foregroundColor,
            'background_color' => $design->backgroundColor,
            'default_theme' => $design->theme,
            'default_error_correction' => $design->errorCorrection,
            'default_logo_attachment_id' => $this->safeImageAttachmentId($design->logoAttachmentId),
            'margin' => $design->margin,
            'logo_size' => $design->logoSize,
            'dot_style' => $design->dotStyle,
            'finder_style' => $design->finderStyle,
            'finder_dot_style' => $design->finderDotStyle,
            'finder_color' => $design->finderColor ?: '',
            'caption_font_family' => $design->captionFontFamily,
            'caption_font_size' => $design->captionFontSize,
            'caption_font_color' => $design->captionFontColor,
        ]), false);
    }

    public function safeImageAttachmentId(int $attachmentId): int {
        if ($attachmentId <= 0) { return 0; }
        return wp_attachment_is_image($attachmentId) ? $attachmentId : 0;
    }

    public function optionName(): string {
        return 'qrbuzz_brand_kit_' . $this->workspaces->id();
    }
}
