<?php
namespace QRBuzz\QR\Types;

use QRBuzz\Models\QRCode;
use QRBuzz\QR\QRGenerator;

require_once __DIR__ . '/PayloadBuilders.php';

class QRPayloadService {

    private QRTypeRegistry $types;
    private QRGenerator $generator;

    /** @var array<string,PayloadBuilderInterface> */
    private array $builders = [];

    public function __construct(?QRTypeRegistry $types = null, ?QRGenerator $generator = null) {
        $this->types = $types ?: new QRTypeRegistry();
        $this->generator = $generator ?: new QRGenerator();

        foreach ([
            new DynamicUrlPayloadBuilder(),
            new StaticUrlPayloadBuilder(),
            new WifiPayloadBuilder(),
            new VCardPayloadBuilder(),
            new EmailPayloadBuilder(),
            new PhonePayloadBuilder(),
            new SmsPayloadBuilder(),
            new TextPayloadBuilder(),
            new LocationPayloadBuilder(),
        ] as $builder) {
            $this->builders[$builder->type()] = $builder;
        }
    }

    public function build(string $type, array $data): string {
        $type = $this->types->normalize($type);
        return trim($this->builders[$type]->build($data));
    }

    public function payloadForQrCode(QRCode $qrCode): string {
        if ($qrCode->isTrackable()) {
            return $this->generator->trackingUrl($qrCode->shortcode);
        }

        return (string) $qrCode->staticPayload;
    }

    public function isPayloadValid(string $type, string $payload): bool {
        if ($payload === '') {
            return false;
        }

        if (in_array($type, ['dynamic_url', 'static_url'], true)) {
            return (bool) wp_http_validate_url($payload);
        }

        if ($type === 'email') {
            return str_starts_with($payload, 'mailto:') && strlen($payload) > 7;
        }

        if ($type === 'phone') {
            return str_starts_with($payload, 'tel:') && strlen($payload) > 4;
        }

        if ($type === 'sms') {
            return str_starts_with($payload, 'sms:') && strlen($payload) > 4;
        }

        if ($type === 'location') {
            return (bool) wp_http_validate_url($payload);
        }

        return true;
    }
}
