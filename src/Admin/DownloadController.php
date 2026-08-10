<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\QRRepository;
use QRBuzz\Models\QRCode;
use QRBuzz\QR\Design\QRDesignSettings;
use QRBuzz\QR\QRGenerator;
use QRBuzz\QR\Types\QRPayloadService;

class DownloadController {

    private QRRepository $repository;
    private QRGenerator $generator;
    private QRPayloadService $payloads;

    public function __construct(?QRRepository $repository = null, ?QRGenerator $generator = null, ?QRPayloadService $payloads = null) {
        $this->repository = $repository ?: new QRRepository();
        $this->generator = $generator ?: new QRGenerator();
        $this->payloads = $payloads ?: new QRPayloadService(null, $this->generator);
    }

    public function init(): void {
        add_action('admin_post_qrbuzz_download_png', [$this, 'downloadPng']);
        add_action('admin_post_qrbuzz_download_svg', [$this, 'downloadSvg']);
    }

    public function downloadPng(): void {
        $qrCode = $this->authorisedQrCode();
        $content = $this->generator->generatePng($this->payloads->payloadForQrCode($qrCode), 600, QRDesignSettings::fromQrCode($qrCode));

        $this->sendDownload($content, $this->filename($qrCode, 'png'), 'image/png');
    }

    public function downloadSvg(): void {
        $qrCode = $this->authorisedQrCode();
        $content = $this->generator->generateSvg($this->payloads->payloadForQrCode($qrCode), 600, QRDesignSettings::fromQrCode($qrCode));

        $this->sendDownload($content, $this->filename($qrCode, 'svg'), 'image/svg+xml');
    }

    private function authorisedQrCode(): QRCode {
        if (!is_user_logged_in()) {
            wp_die(esc_html__('You do not have permission to download QR codes.', 'qr-buzz'), 403);
        }

        $id = isset($_GET['qr_id']) ? absint($_GET['qr_id']) : 0;
        check_admin_referer('qrbuzz_download_qr_' . $id);

        $qrCode = $this->repository->find($id);

        if (!$qrCode) {
            wp_die(esc_html__('QR code not found.', 'qr-buzz'), 404);
        }

        return $qrCode;
    }

    private function sendDownload(string $content, string $filename, string $mimeType): void {
        nocache_headers();
        header('Content-Type: ' . $mimeType);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($content));
        echo $content;
        exit;
    }

    private function filename(QRCode $qrCode, string $extension): string {
        $name = sanitize_title($qrCode->name);

        if ($name === '') {
            $name = strtolower($qrCode->shortcode);
        }

        return $name . '-' . strtolower($qrCode->shortcode) . '.' . $extension;
    }
}
