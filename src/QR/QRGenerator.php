<?php
namespace QRBuzz\QR;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;

class QRGenerator {

    public function generate(string $data, int $size = 300): string {
        return $this->generatePngDataUri($data, $size);
    }

    public function generatePngDataUri(string $data, int $size = 300): string {
        return (new PngWriter())->write($this->qrCode($data, $size))->getDataUri();
    }

    public function generatePng(string $data, int $size = 600): string {
        return (new PngWriter())->write($this->qrCode($data, $size))->getString();
    }

    public function generateSvg(string $data, int $size = 600): string {
        if (!class_exists(SvgWriter::class)) {
            throw new \RuntimeException('SVG QR downloads are not available with the bundled QR library.');
        }

        return (new SvgWriter())->write($this->qrCode($data, $size))->getString();
    }

    public function supportsSvg(): bool {
        return class_exists(SvgWriter::class);
    }

    public function trackingUrl(string $shortcode): string {
        return home_url('/q/' . rawurlencode($shortcode));
    }

    private function qrCode(string $data, int $size): QrCode {
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

        return $qrCode;
    }
}
