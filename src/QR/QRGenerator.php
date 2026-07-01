<?php
namespace QRBuzz\QR;

class QRGenerator {

    public function generate(string $url): string {
        $encoded = urlencode($url);

        // v0.1.0 uses external QR service (swap later with local generator)
        return "https://api.qrserver.com/v1/create-qr-code/?size=300x300&data={$encoded}";
    }
}
