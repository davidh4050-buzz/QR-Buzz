<?php
namespace QRBuzz\Core;

class Requirements {

    public static function dependenciesLoaded(): bool {
        return class_exists('Endroid\\QrCode\\QrCode') && class_exists('Endroid\\QrCode\\Writer\\PngWriter');
    }

    public static function missingDependenciesMessage(): string {
        return 'QR Buzz needs its bundled Composer dependencies to generate QR images. Install the release ZIP built by GitHub Actions, not the GitHub source-code ZIP.';
    }

    public function init(): void {
        if (!self::dependenciesLoaded()) {
            add_action('admin_notices', [$this, 'dependencyNotice']);
        }
    }

    public function dependencyNotice(): void {
        if (!current_user_can('activate_plugins')) {
            return;
        }

        echo '<div class="notice notice-error"><p>' . esc_html(self::missingDependenciesMessage()) . '</p></div>';
    }
}
