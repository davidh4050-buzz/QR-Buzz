<?php
namespace QRBuzz\Redirect;

class Resolution {

    public ?string $destinationUrl;
    public string $reason;
    public string $scanStatus;
    public string $message;
    public bool $shouldRedirect;

    private function __construct(?string $destinationUrl, string $reason, string $scanStatus, string $message, bool $shouldRedirect) {
        $this->destinationUrl = $destinationUrl;
        $this->reason = $reason;
        $this->scanStatus = $scanStatus;
        $this->message = $message;
        $this->shouldRedirect = $shouldRedirect;
    }

    public static function redirect(string $destinationUrl, string $reason, string $scanStatus = 'redirected'): self {
        return new self($destinationUrl, $reason, $scanStatus, '', true);
    }

    public static function message(string $reason, string $scanStatus, string $message): self {
        return new self(null, $reason, $scanStatus, $message, false);
    }
}
