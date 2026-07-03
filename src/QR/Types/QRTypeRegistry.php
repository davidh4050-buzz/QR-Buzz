<?php
namespace QRBuzz\QR\Types;

class QRTypeRegistry {

    public const DYNAMIC_URL = 'dynamic_url';

    /**
     * @return array<string,array{label:string,trackable:bool,description:string}>
     */
    public function all(): array {
        return [
            'dynamic_url' => ['label' => 'Website / Dynamic URL', 'trackable' => true, 'description' => 'Editable destination with QR Buzz tracking and analytics.'],
            'static_url' => ['label' => 'Static URL', 'trackable' => false, 'description' => 'Encode a final website URL directly into the QR image.'],
            'wifi' => ['label' => 'WiFi', 'trackable' => false, 'description' => 'Let visitors join a wireless network.'],
            'vcard' => ['label' => 'Business Card', 'trackable' => false, 'description' => 'Share contact details as a vCard.'],
            'email' => ['label' => 'Email', 'trackable' => false, 'description' => 'Open a prefilled email.'],
            'phone' => ['label' => 'Phone', 'trackable' => false, 'description' => 'Start a phone call.'],
            'sms' => ['label' => 'SMS', 'trackable' => false, 'description' => 'Open a prefilled text message.'],
            'location' => ['label' => 'Location', 'trackable' => false, 'description' => 'Open a map location.'],
            'text' => ['label' => 'Text', 'trackable' => false, 'description' => 'Encode plain text directly.'],
        ];
    }

    public function exists(string $type): bool {
        return isset($this->all()[$type]);
    }

    public function label(string $type): string {
        $types = $this->all();
        return $types[$type]['label'] ?? $types[self::DYNAMIC_URL]['label'];
    }

    public function isTrackable(string $type): bool {
        $types = $this->all();
        return (bool) ($types[$type]['trackable'] ?? true);
    }

    public function normalize(string $type): string {
        return $this->exists($type) ? $type : self::DYNAMIC_URL;
    }
}
