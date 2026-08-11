<?php
namespace QRBuzz\QR\Design;

class QRThemeRegistry {

    public function all(): array {
        return [
            'classic' => ['label' => 'Classic', 'foreground_color' => '#000000', 'background_color' => '#ffffff', 'transparent_background' => 0, 'error_correction' => 'M', 'margin' => 12, 'logo_size' => 20],
            'modern' => ['label' => 'Modern', 'foreground_color' => '#16324f', 'background_color' => '#f7fafc', 'transparent_background' => 0, 'error_correction' => 'Q', 'margin' => 14, 'logo_size' => 20],
            'rounded' => ['label' => 'Rounded', 'foreground_color' => '#123b3a', 'background_color' => '#f4fbf8', 'transparent_background' => 0, 'error_correction' => 'Q', 'margin' => 14, 'logo_size' => 20],
            'dark' => ['label' => 'Dark', 'foreground_color' => '#ffffff', 'background_color' => '#111827', 'transparent_background' => 0, 'error_correction' => 'H', 'margin' => 16, 'logo_size' => 18],
            'minimal' => ['label' => 'Minimal', 'foreground_color' => '#202124', 'background_color' => '#ffffff', 'transparent_background' => 0, 'error_correction' => 'M', 'margin' => 8, 'logo_size' => 18],
            'high_contrast' => ['label' => 'High Contrast', 'foreground_color' => '#000000', 'background_color' => '#ffffff', 'transparent_background' => 0, 'error_correction' => 'H', 'margin' => 16, 'logo_size' => 18],
            'corporate' => ['label' => 'Corporate', 'foreground_color' => '#0f4c81', 'background_color' => '#ffffff', 'transparent_background' => 0, 'error_correction' => 'Q', 'margin' => 14, 'logo_size' => 20],
        ];
    }

    public function get(string $theme): array {
        $themes = $this->all();
        $theme = sanitize_key($theme);
        return $themes[$theme] ?? $themes['classic'];
    }

    public function label(string $theme): string {
        $settings = $this->get($theme);
        return (string) $settings['label'];
    }

    public function designSettings(string $theme, array $overrides = []): QRDesignSettings {
        $settings = $this->get($theme);
        unset($settings['label']);
        $settings['theme'] = sanitize_key($theme);

        return new QRDesignSettings(array_merge($settings, $overrides));
    }
}
