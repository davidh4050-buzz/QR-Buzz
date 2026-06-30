<?php

namespace QRBuzz\Admin;

use QRBuzz\QR\QRGenerator;

class AdminPage
{
    public function init(): void
    {
        add_action('admin_menu', [$this, 'registerMenu']);
    }

    public function registerMenu(): void
    {
        add_menu_page(
            'QR Buzz',
            'QR Buzz',
            'manage_options',
            'qr-buzz',
            [$this, 'renderPage'],
            'dashicons-qr-code',
            25
        );
    }

    public function renderPage(): void
    {
        $qrImage = null;

        if (!empty($_POST['qr_url'])) {
            $generator = new QRGenerator();
            $qrImage = $generator->generate($_POST['qr_url']);
        }

        ?>
        <div class="wrap">
            <h1>QR Buzz</h1>

            <form method="post">
                <input type="text" name="qr_url" placeholder="Enter URL" style="width: 400px;" required>
                <button type="submit" class="button button-primary">Generate QR</button>
            </form>

            <?php if ($qrImage): ?>
                <h2>Your QR Code</h2>
                <img src="<?php echo esc_attr($qrImage); ?>" style="margin-top:20px; max-width:300px;">
            <?php endif; ?>
        </div>
        <?php
    }
}
