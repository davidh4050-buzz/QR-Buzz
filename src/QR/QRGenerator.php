<?php
namespace QRBuzz\QR;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;

class QRGenerator {

    public function generate(string $data, int $size = 300): string {
        $data = trim($data);

        if ($data === '') {
            throw new \InvalidArgumentException('Please enter a URL to generate a QR code.');
        }

        if (!class_exists(QrCode::class)) {
            throw new \RuntimeException('QR Buzz dependencies are missing. Run composer install before using the development copy.');
        }

        $qrCode = new QrCode($data);
        $qrCode->setEncoding(new Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel(new ErrorCorrectionLevelHigh());
        $qrCode->setSize($size);
        $qrCode->setMargin(12);

        $result = (new PngWriter())->write($qrCode);

        return $result->getDataUri();
    }

    public function trackingUrl(string $shortcode): string {
        return home_url('/q/' . rawurlencode($shortcode));
    }
}
