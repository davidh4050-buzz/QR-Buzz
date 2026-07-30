<?php
namespace QRBuzz\QR\Design;

use QRBuzz\Models\QRCode;

class QRDesignSettings {

    public string $theme;
    public string $foregroundColor;
    public string $backgroundColor;
    public bool $transparentBackground;
    public string $errorCorrection;
    public int $margin;
    public int $logoAttachmentId;
    public int $logoSize;
    public string $dotStyle;
    public string $finderStyle;
    public string $finderDotStyle;
    public ?string $finderColor;
    public string $caption;
    public int $captionFontSize;
    public string $captionFontColor;

    public function __construct(array $settings = []) {
        $this->theme = self::sanitizeTheme((string) ($settings['theme'] ?? 'classic'));
        $this->foregroundColor = self::sanitizeColor((string) ($settings['foreground_color'] ?? '#000000'), '#000000');
        $this->backgroundColor = self::sanitizeColor((string) ($settings['background_color'] ?? '#ffffff'), '#ffffff');
        $this->transparentBackground = !empty($settings['transparent_background']);
        $this->errorCorrection = self::sanitizeErrorCorrection((string) ($settings['error_correction'] ?? 'H'));
        $this->margin = self::sanitizeMargin($settings['margin'] ?? 12);
        $this->logoAttachmentId = absint($settings['logo_attachment_id'] ?? 0);
        $this->logoSize = self::sanitizeLogoSize($settings['logo_size'] ?? 20);
        $this->dotStyle = self::sanitizeDotStyle((string) ($settings['dot_style'] ?? 'square'));
        $this->finderStyle = self::sanitizeFinderStyle((string) ($settings['finder_style'] ?? 'square'));
        $this->finderDotStyle = self::sanitizeFinderDotStyle((string) ($settings['finder_dot_style'] ?? 'square'));
        $this->finderColor = self::sanitizeOptionalColor($settings['finder_color'] ?? null);
        $this->caption = self::sanitizeCaption((string) ($settings['caption'] ?? ''));
        $this->captionFontSize = self::sanitizeCaptionFontSize($settings['caption_font_size'] ?? 16);
        $this->captionFontColor = self::sanitizeColor((string) ($settings['caption_font_color'] ?? '#000000'), '#000000');
    }

    public static function defaults(): self {
        return new self();
    }

    public static function fromQrCode(QRCode $qrCode): self {
        return new self([
            'theme' => $qrCode->theme,
            'foreground_color' => $qrCode->foregroundColor,
            'background_color' => $qrCode->backgroundColor,
            'transparent_background' => $qrCode->transparentBackground,
            'error_correction' => $qrCode->errorCorrection,
            'margin' => $qrCode->margin,
            'logo_attachment_id' => $qrCode->logoAttachmentId,
            'logo_size' => $qrCode->logoSize,
            'dot_style' => $qrCode->dotStyle,
            'finder_style' => $qrCode->finderStyle,
            'finder_dot_style' => $qrCode->finderDotStyle,
            'finder_color' => $qrCode->finderColor,
            'caption' => $qrCode->caption,
            'caption_font_size' => $qrCode->captionFontSize,
            'caption_font_color' => $qrCode->captionFontColor,
        ]);
    }

    public static function fromPost(array $post, ?self $fallback = null): self {
        $fallback = $fallback ?: self::defaults();

        return new self([
            'theme' => isset($post['theme']) ? sanitize_key(wp_unslash($post['theme'])) : $fallback->theme,
            'foreground_color' => isset($post['foreground_color']) ? sanitize_text_field(wp_unslash($post['foreground_color'])) : $fallback->foregroundColor,
            'background_color' => isset($post['background_color']) ? sanitize_text_field(wp_unslash($post['background_color'])) : $fallback->backgroundColor,
            'transparent_background' => !empty($post['transparent_background']),
            'error_correction' => isset($post['error_correction']) ? sanitize_text_field(wp_unslash($post['error_correction'])) : $fallback->errorCorrection,
            'margin' => isset($post['margin']) ? absint($post['margin']) : $fallback->margin,
            'logo_attachment_id' => isset($post['logo_attachment_id']) ? absint($post['logo_attachment_id']) : $fallback->logoAttachmentId,
            'logo_size' => isset($post['logo_size']) ? absint($post['logo_size']) : $fallback->logoSize,
            'dot_style' => isset($post['dot_style']) ? sanitize_key(wp_unslash($post['dot_style'])) : $fallback->dotStyle,
            'finder_style' => isset($post['finder_style']) ? sanitize_key(wp_unslash($post['finder_style'])) : $fallback->finderStyle,
            'finder_dot_style' => isset($post['finder_dot_style']) ? sanitize_key(wp_unslash($post['finder_dot_style'])) : $fallback->finderDotStyle,
            'finder_color' => isset($post['finder_color']) ? sanitize_text_field(wp_unslash($post['finder_color'])) : $fallback->finderColor,
            'caption' => isset($post['caption']) ? sanitize_text_field(wp_unslash($post['caption'])) : $fallback->caption,
            'caption_font_size' => isset($post['caption_font_size']) ? absint($post['caption_font_size']) : $fallback->captionFontSize,
            'caption_font_color' => isset($post['caption_font_color']) ? sanitize_text_field(wp_unslash($post['caption_font_color'])) : $fallback->captionFontColor,
        ]);
    }

    public function toArray(): array {
        return [
            'theme' => $this->theme,
            'foreground_color' => $this->foregroundColor,
            'background_color' => $this->backgroundColor,
            'transparent_background' => $this->transparentBackground ? 1 : 0,
            'error_correction' => $this->errorCorrection,
            'margin' => $this->margin,
            'logo_attachment_id' => $this->logoAttachmentId,
            'logo_size' => $this->logoSize,
            'dot_style' => $this->dotStyle,
            'finder_style' => $this->finderStyle,
            'finder_dot_style' => $this->finderDotStyle,
            'finder_color' => $this->finderColor,
            'caption' => $this->caption,
            'caption_font_size' => $this->captionFontSize,
            'caption_font_color' => $this->captionFontColor,
        ];
    }

    public function hasLogo(): bool {
        return $this->logoAttachmentId > 0;
    }

    public function usesAdvancedRendering(): bool {
        return $this->dotStyle !== 'square'
            || $this->finderStyle !== 'square'
            || $this->finderDotStyle !== 'square'
            || $this->finderColor !== null
            || $this->caption !== '';
    }

    public static function sanitizeTheme(string $theme): string {
        $theme = sanitize_key($theme);
        return $theme !== '' ? $theme : 'classic';
    }

    public static function sanitizeColor(string $color, string $fallback): string {
        $color = trim($color);
        return preg_match('/^#[0-9a-fA-F]{6}$/', $color) ? strtolower($color) : $fallback;
    }

    public static function sanitizeErrorCorrection(string $level): string {
        $level = strtoupper(trim($level));
        return in_array($level, ['L', 'M', 'Q', 'H'], true) ? $level : 'H';
    }

    public static function sanitizeMargin($margin): int {
        return max(0, min(40, absint($margin)));
    }

    public static function sanitizeLogoSize($size): int {
        return max(10, min(35, absint($size)));
    }

    public static function sanitizeDotStyle(string $style): string {
        $style = sanitize_key($style);
        $aliases = ['dots' => 'dot', 'round' => 'dot', 'circle' => 'dot'];
        $style = $aliases[$style] ?? $style;
        return in_array($style, ['square', 'dot', 'rounded'], true) ? $style : 'square';
    }

    public static function sanitizeFinderStyle(string $style): string {
        $style = sanitize_key($style);
        $aliases = ['dot' => 'circle', 'dots' => 'circle'];
        $style = $aliases[$style] ?? $style;
        return in_array($style, ['square', 'rounded', 'circle'], true) ? $style : 'square';
    }

    public static function sanitizeFinderDotStyle(string $style): string {
        $style = sanitize_key($style);
        $aliases = ['circle' => 'dot', 'dots' => 'dot'];
        $style = $aliases[$style] ?? $style;
        return in_array($style, ['square', 'rounded', 'dot'], true) ? $style : 'square';
    }

    public static function sanitizeOptionalColor($color): ?string {
        if ($color === null || trim((string) $color) === '') {
            return null;
        }
        $sanitized = self::sanitizeColor((string) $color, '');
        return $sanitized === '' ? null : $sanitized;
    }

    public static function sanitizeCaption(string $caption): string {
        return substr(trim(sanitize_text_field($caption)), 0, 120);
    }

    public static function sanitizeCaptionFontSize($size): int {
        return max(8, min(40, absint($size)));
    }
}
