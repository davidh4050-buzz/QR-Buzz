<?php
namespace QRBuzz\QR;

use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelInterface;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelQuartile;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Logo\LogoInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use QRBuzz\QR\Design\QRDesignSettings;

class QRGenerator {

    public function generate(string $data, int $size = 300, ?QRDesignSettings $design = null): string {
        return $this->generatePngDataUri($data, $size, $design);
    }

    public function generatePngDataUri(string $data, int $size = 300, ?QRDesignSettings $design = null): string {
        $design = $design ?: QRDesignSettings::defaults();
        $qrCode = $this->qrCode($data, $size, $design);
        $logo = $this->logo($design, $size, 'png');

        return (new PngWriter())->write($qrCode, $logo)->getDataUri();
    }

    public function generatePng(string $data, int $size = 600, ?QRDesignSettings $design = null): string {
        $design = $design ?: QRDesignSettings::defaults();
        $qrCode = $this->qrCode($data, $size, $design);
        $logo = $this->logo($design, $size, 'png');

        return (new PngWriter())->write($qrCode, $logo)->getString();
    }

    public function generateSvg(string $data, int $size = 600, ?QRDesignSettings $design = null): string {
        if (!class_exists(SvgWriter::class)) {
            throw new \RuntimeException('SVG QR downloads are not available with the bundled QR library.');
        }

        $design = $design ?: QRDesignSettings::defaults();
        $qrCode = $this->qrCode($data, $size, $design);
        $logo = $this->logo($design, $size, 'svg');

        return (new SvgWriter())->write($qrCode, $logo)->getString();
    }

    public function supportsSvg(): bool {
        return class_exists(SvgWriter::class);
    }

    public function trackingUrl(string $shortcode): string {
        return home_url('/q/' . rawurlencode($shortcode));
    }

    private function qrCode(string $data, int $size, QRDesignSettings $design): QrCode {
        $data = trim($data);

        if ($data === '') {
            throw new \InvalidArgumentException('Please enter content to generate a QR code.');
        }

        if (!class_exists(QrCode::class) || !class_exists(PngWriter::class)) {
            throw new \RuntimeException('QR Buzz dependencies are missing. Run composer install before using the development copy.');
        }

        $qrCode = new QrCode($data);
        $qrCode->setEncoding(new Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel($this->errorCorrection($design->errorCorrection));
        $qrCode->setSize($size);
        $qrCode->setMargin($design->margin);
        $qrCode->setForegroundColor($this->color($design->foregroundColor, false));
        $qrCode->setBackgroundColor($this->color($design->backgroundColor, $design->transparentBackground));

        return $qrCode;
    }

    private function errorCorrection(string $level): ErrorCorrectionLevelInterface {
        return match (strtoupper($level)) {
            'L' => new ErrorCorrectionLevelLow(),
            'M' => new ErrorCorrectionLevelMedium(),
            'Q' => new ErrorCorrectionLevelQuartile(),
            default => new ErrorCorrectionLevelHigh(),
        };
    }

    private function color(string $hex, bool $transparent): Color {
        $hex = ltrim(QRDesignSettings::sanitizeColor($hex, '#ffffff'), '#');
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        return new Color($red, $green, $blue, $transparent ? 127 : 0);
    }

    private function logo(QRDesignSettings $design, int $size, string $format): ?LogoInterface {
        if (!$design->hasLogo() || !function_exists('get_attached_file')) {
            return null;
        }

        $path = get_attached_file($design->logoAttachmentId);
        if (!$path || !file_exists($path)) {
            return null;
        }

        $fileType = wp_check_filetype($path);
        if ($format === 'png' && ($fileType['type'] ?? '') === 'image/svg+xml') {
            return null;
        }

        $width = max(24, (int) round($size * ($design->logoSize / 100)));

        return Logo::create($path)
            ->setResizeToWidth($width)
            ->setPunchoutBackground(true);
    }
}
