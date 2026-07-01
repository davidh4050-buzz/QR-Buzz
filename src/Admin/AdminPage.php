<?php
namespace QRBuzz\Admin;

use QRBuzz\QR\QRGenerator;

class AdminPage {

    public function init(): void {
        add_action('admin_menu', [$this, 'menu']);
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

    public function render(): void {
        if (!current_user_can('manage_options')) {
            wp_die(esc_html__('You do not have permission to access QR Buzz.', 'qr-buzz'));
        }

        $qr = null;
        $error = null;
        $url = '';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            check_admin_referer('qr_buzz_generate', 'qr_buzz_nonce');
            $url = isset($_POST['qr_url']) ? esc_url_raw(wp_unslash($_POST['qr_url'])) : '';

            try {
                $qr = (new QRGenerator())->generate($url);
            } catch (\InvalidArgumentException $exception) {
                $error = $exception->getMessage();
            }
        }

        echo '<div class="wrap"><h1>QR Buzz</h1>';

        if ($error) {
            echo '<div class="notice notice-error"><p>' . esc_html($error) . '</p></div>';
        }

        echo '<form method="post">';
        wp_nonce_field('qr_buzz_generate', 'qr_buzz_nonce');
        echo '<p><input class="regular-text" name="qr_url" type="url" placeholder="Enter URL" value="' . esc_attr($url) . '" required /></p>';
        echo '<p><button class="button button-primary">Generate</button></p>';
        echo '</form>';

        if ($qr) {
            echo '<h2>QR Code</h2>';
            echo '<img src="' . esc_attr($qr) . '" width="300" height="300" alt="Generated QR code" />';
        }

        echo '</div>';
    }
}
