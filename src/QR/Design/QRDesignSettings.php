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

    public function __construct(array $settings = []) {
        $this->theme = self::sanitizeTheme((string) ($settings['theme'] ?? 'classic'));
        $this->foregroundColor = self::sanitizeColor((string) ($settings['foreground_color'] ?? '#000000'), '#000000');
        $this->backgroundColor = self::sanitizeColor((string) ($settings['background_color'] ?? '#ffffff'), '#ffffff');
        $this->transparentBackground = !empty($settings['transparent_background']);
        $this->errorCorrection = self::sanitizeErrorCorrection((string) ($settings['error_correction'] ?? 'H'));
        $this->margin = self::sanitizeMargin($settings['margin'] ?? 12);
        $this->logoAttachmentId = absint($settings['logo_attachment_id'] ?? 0);
        $this->logoSize = self::sanitizeLogoSize($settings['logo_size'] ?? 20);
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
        ];
    }

    public function hasLogo(): bool {
        return $this->logoAttachmentId > 0;
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
}
