<?php
namespace QRBuzz\Redirect;

class Resolution {

    public ?string $destinationUrl;
    public string $reason;
    public string $scanStatus;
    public string $message;
    public bool $shouldRedirect;
    public ?int $matchedRuleId;
    public ?string $matchedRuleName;

    private function __construct(?string $destinationUrl, string $reason, string $scanStatus, string $message, bool $shouldRedirect, ?int $matchedRuleId = null, ?string $matchedRuleName = null) {
        $this->destinationUrl = $destinationUrl;
        $this->reason = $reason;
        $this->scanStatus = $scanStatus;
        $this->message = $message;
        $this->shouldRedirect = $shouldRedirect;
        $this->matchedRuleId = $matchedRuleId;
        $this->matchedRuleName = $matchedRuleName;
    }

    public static function redirect(string $destinationUrl, string $reason, string $scanStatus = 'redirected', ?int $matchedRuleId = null, ?string $matchedRuleName = null): self {
        return new self($destinationUrl, $reason, $scanStatus, '', true, $matchedRuleId, $matchedRuleName);
    }

    public static function message(string $reason, string $scanStatus, string $message): self {
        return new self(null, $reason, $scanStatus, $message, false);
    }
}
