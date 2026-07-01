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
        $qr = null;

        if (!empty($_POST['qr_url'])) {
            $url = esc_url_raw($_POST['qr_url']);
            $qr = (new QRGenerator())->generate($url);
        }

        echo '<div class="wrap"><h1>QR Buzz</h1>';

        echo '<form method="post">';
        echo '<input style="width:400px" name="qr_url" placeholder="Enter URL" required />';
        echo '<button class="button button-primary">Generate</button>';
        echo '</form>';

        if ($qr) {
            echo '<h2>QR Code</h2>';
            echo '<img src="'.esc_attr($qr).'" />';
        }

        echo '</div>';
    }
}
