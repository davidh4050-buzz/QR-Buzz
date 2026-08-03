<?php
namespace QRBuzz\SmartDestinations;

use QRBuzz\Models\DestinationRule;
use QRBuzz\Models\QRCode;
use QRBuzz\Utils\DateTimeHelper;

class RuleConflictAnalyzer {
    public function analyze(QRCode $qr, array $rules): array {
        $warnings = [];
        if ($qr->status === 'paused' || !$qr->active) { $warnings[] = $this->warning('information', 'QR is paused, so rules are not currently evaluated.'); }
        if ($qr->isExpired()) { $warnings[] = $this->warning('warning', 'QR is expired, so expiry handling overrides all rules.'); }
        if (!$qr->fallbackUrl) { $warnings[] = $this->warning('information', 'No fallback destination is configured.'); }
        $seen = [];
        $always = null;
        foreach ($rules as $rule) {
            $signature = implode('|', [(string) $rule->startsAt, (string) $rule->endsAt, (string) $rule->daysOfWeek, (string) $rule->timeStart, (string) $rule->timeEnd]);
            if ($rule->endsAt && DateTimeHelper::utcTimestamp($rule->endsAt) < current_time('timestamp', true)) { $warnings[] = $this->warning('warning', '“' . $rule->name . '” has a date range that has already passed.', $rule->id); }
            if ($signature === '||||') { $warnings[] = $this->warning('information', '“' . $rule->name . '” has no conditions and always matches.', $rule->id); if ($rule->isActive() && $always === null) { $always = $rule; } }
            elseif ($always && $rule->isActive()) { $warnings[] = $this->warning('warning', '“' . $rule->name . '” is unreachable because the higher-priority rule “' . $always->name . '” always matches.', $rule->id); }
            if (isset($seen[$signature])) { $warnings[] = $this->warning('warning', '“' . $rule->name . '” has identical conditions to “' . $seen[$signature]->name . '”.', $rule->id); } else { $seen[$signature] = $rule; }
            if ($rule->destinationUrl === $qr->destinationUrl) { $warnings[] = $this->warning('information', '“' . $rule->name . '” uses the primary destination.', $rule->id); }
        }
        return $warnings;
    }
    private function warning(string $severity, string $message, ?int $ruleId = null): array { return ['severity' => $severity, 'message' => $message, 'rule_id' => $ruleId]; }
}
