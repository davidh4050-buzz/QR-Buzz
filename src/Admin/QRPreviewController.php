<?php
namespace QRBuzz\Admin;

use QRBuzz\Database\QRRepository;
use QRBuzz\Membership\EntitlementService;
use QRBuzz\QR\Design\BrandKitSettings;
use QRBuzz\QR\Design\QRDesignSettings;
use QRBuzz\QR\QRGenerator;
use QRBuzz\QR\Types\QRPayloadService;
use QRBuzz\QR\Types\QRTypeRegistry;

class QRPreviewController {

    private QRRepository $repository;
    private QRTypeRegistry $types;
    private QRPayloadService $payloads;
    private QRGenerator $generator;
    private BrandKitSettings $brandKit;
    private EntitlementService $entitlements;

    public function __construct(?QRRepository $repository = null, ?QRTypeRegistry $types = null, ?QRPayloadService $payloads = null, ?QRGenerator $generator = null, ?BrandKitSettings $brandKit = null, ?EntitlementService $entitlements = null) {
        $this->repository = $repository ?: new QRRepository();
        $this->types = $types ?: new QRTypeRegistry();
        $this->generator = $generator ?: new QRGenerator();
        $this->payloads = $payloads ?: new QRPayloadService($this->types, $this->generator);
        $this->brandKit = $brandKit ?: new BrandKitSettings();
        $this->entitlements = $entitlements ?: new EntitlementService();
    }

    public function init(): void {
        add_action('wp_ajax_qrbuzz_preview', [$this, 'preview']);
    }

    public function preview(): void {
        if (!is_user_logged_in()) {
            wp_send_json_error(['message' => __('You do not have permission to preview QR codes.', 'qr-buzz')], 403);
        }

        check_ajax_referer('qrbuzz_preview', 'nonce');

        $qrId = isset($_POST['qr_id']) ? absint($_POST['qr_id']) : 0;
        $existing = $qrId > 0 ? $this->repository->find($qrId) : null;
        $type = $this->types->normalize(isset($_POST['type']) ? sanitize_key(wp_unslash($_POST['type'])) : ($existing ? $existing->type : 'dynamic_url'));
        $designFallback = $existing ? QRDesignSettings::fromQrCode($existing) : $this->brandKit->designDefaults();
        $design = QRDesignSettings::fromPost($_POST, $designFallback);
        if (!$this->entitlements->allows('qr_styling')) { $design = QRDesignSettings::defaults(); }
        if (!$this->entitlements->allows('logo_embedding')) { $design->logoAttachmentId = $existing ? $designFallback->logoAttachmentId : 0; $design->logoAssetId = $existing ? $designFallback->logoAssetId : 0; $design->logoSize = $existing ? $designFallback->logoSize : 20; }
        $payload = $existing && $type === 'dynamic_url' && $existing->type === 'dynamic_url' ? $this->payloads->payloadForQrCode($existing) : $this->previewPayload($type);

        if ($payload === '') {
            wp_send_json_error(['message' => __('Add QR content before refreshing the preview.', 'qr-buzz')], 400);
        }

        try {
            wp_send_json_success([
                'data_uri' => $this->generator->generatePngDataUri($payload, 280, $design),
                'message' => __('Preview refreshed. Save the QR code to keep these settings.', 'qr-buzz'),
            ]);
        } catch (\Throwable $exception) {
            wp_send_json_error(['message' => __('Preview could not be generated with the current settings.', 'qr-buzz')], 500);
        }
    }

    private function previewPayload(string $type): string {
        $payload = isset($_POST['payload']) && is_array($_POST['payload']) ? wp_unslash($_POST['payload']) : [];
        $clean = [];

        foreach ($payload as $key => $value) {
            $clean[sanitize_key((string) $key)] = is_scalar($value) ? sanitize_textarea_field((string) $value) : '';
        }

        if ($type === 'dynamic_url' && isset($clean['destination_url'])) {
            $clean['destination_url'] = esc_url_raw($clean['destination_url']);
        }

        if ($type === 'static_url' && isset($clean['url'])) {
            $clean['url'] = esc_url_raw($clean['url']);
        }

        if ($type === 'vcard' && isset($clean['website'])) {
            $clean['website'] = esc_url_raw($clean['website']);
        }

        if ($type === 'email' && isset($clean['recipient'])) {
            $clean['recipient'] = sanitize_email($clean['recipient']);
        }

        $clean['hidden'] = !empty($payload['hidden']) ? '1' : '';
        $built = $this->payloads->build($type, $clean);

        return $this->payloads->isPayloadValid($type, $built) ? $built : '';
    }
}
