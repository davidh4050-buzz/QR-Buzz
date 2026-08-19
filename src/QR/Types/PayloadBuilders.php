<?php
namespace QRBuzz\QR\Types;

class DynamicUrlPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'dynamic_url'; }
    public function build(array $data): string { return esc_url_raw((string) ($data['destination_url'] ?? '')); }
}

class StaticUrlPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'static_url'; }
    public function build(array $data): string { return esc_url_raw((string) ($data['url'] ?? '')); }
}

class WifiPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'wifi'; }
    public function build(array $data): string {
        $encryption = strtoupper((string) ($data['encryption'] ?? 'WPA'));
        $encryption = in_array($encryption, ['WPA', 'WEP', 'NOPASS'], true) ? $encryption : 'WPA';
        $hidden = !empty($data['hidden']) ? 'true' : 'false';
        return 'WIFI:T:' . $encryption . ';S:' . $this->escape((string) ($data['ssid'] ?? '')) . ';P:' . $this->escape((string) ($data['password'] ?? '')) . ';H:' . $hidden . ';;';
    }
    private function escape(string $value): string { return str_replace(['\\', ';', ',', ':', '"'], ['\\\\', '\;', '\,', '\:', '\"'], $value); }
}

class VCardPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'vcard'; }
    public function build(array $data): string {
        $first = $this->line((string) ($data['first_name'] ?? ''));
        $last = $this->line((string) ($data['last_name'] ?? ''));
        $lines = ['BEGIN:VCARD', 'VERSION:3.0', 'N:' . $last . ';' . $first . ';;;', 'FN:' . trim($first . ' ' . $last)];
        $map = ['organisation' => 'ORG', 'job_title' => 'TITLE', 'phone' => 'TEL', 'email' => 'EMAIL', 'website' => 'URL', 'address' => 'ADR;TYPE=WORK'];
        foreach ($map as $key => $label) {
            $value = $this->line((string) ($data[$key] ?? ''));
            if ($value !== '') { $lines[] = $label . ':' . $value; }
        }
        $lines[] = 'END:VCARD';
        return implode("\n", $lines);
    }
    private function line(string $value): string { return str_replace(["\r", "\n"], [' ', ' '], trim($value)); }
}

class EmailPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'email'; }
    public function build(array $data): string {
        $recipient = sanitize_email((string) ($data['recipient'] ?? ''));
        $query = http_build_query(array_filter(['subject' => (string) ($data['subject'] ?? ''), 'body' => (string) ($data['body'] ?? '')], static fn($value): bool => $value !== ''));
        return 'mailto:' . $recipient . ($query !== '' ? '?' . $query : '');
    }
}

class PhonePayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'phone'; }
    public function build(array $data): string { return 'tel:' . preg_replace('/[^0-9+(). -]/', '', (string) ($data['phone'] ?? '')); }
}

class SmsPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'sms'; }
    public function build(array $data): string {
        $phone = preg_replace('/[^0-9+(). -]/', '', (string) ($data['phone'] ?? ''));
        $message = (string) ($data['message'] ?? '');
        return 'sms:' . $phone . ($message !== '' ? '?body=' . rawurlencode($message) : '');
    }
}

class TextPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'text'; }
    public function build(array $data): string { return sanitize_textarea_field((string) ($data['text'] ?? '')); }
}

class LocationPayloadBuilder implements PayloadBuilderInterface {
    public function type(): string { return 'location'; }
    public function build(array $data): string {
        $lat = trim((string) ($data['latitude'] ?? ''));
        $lng = trim((string) ($data['longitude'] ?? ''));
        $label = trim((string) ($data['label'] ?? ''));
        $query = $lat !== '' && $lng !== '' ? $lat . ',' . $lng : $label;
        if ($label !== '' && $lat !== '' && $lng !== '') {
            $query .= ' (' . $label . ')';
        }
        return 'https://www.google.com/maps/search/?api=1&query=' . rawurlencode($query);
    }
}
