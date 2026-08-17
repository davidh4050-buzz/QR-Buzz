<?php
namespace QRBuzz\Redirect;

use QRBuzz\Database\DestinationRuleRepository;
use QRBuzz\Models\DestinationRule;
use QRBuzz\Models\QRCode;
use QRBuzz\Utils\DateTimeHelper;

class DestinationResolver {

    private DestinationRuleRepository $rules;

    public function __construct(?DestinationRuleRepository $rules = null) {
        $this->rules = $rules ?: new DestinationRuleRepository();
    }

    public function resolve(QRCode $qrCode, ?int $timestamp = null): Resolution {
        $timestamp = $timestamp ?: current_time('timestamp', true);

        if ($qrCode->isExpired($timestamp)) {
            return $this->fallbackOrMessage($qrCode, 'expired', 'expired', 'This QR code has expired.');
        }

        if ($qrCode->status === 'paused' || !$qrCode->active) {
            return $this->fallbackOrMessage($qrCode, 'paused', 'paused', 'This QR code is currently paused.');
        }

        foreach ($this->rules->forQrCode($qrCode->id, true) as $rule) {
            if (!$this->ruleMatches($rule, $timestamp)) {
                continue;
            }

            if ($this->isValidUrl($rule->destinationUrl)) {
                return Resolution::redirect($rule->destinationUrl, 'smart_rule', 'redirected', $rule->id, $rule->name);
            }

            return $this->fallbackOrMessage($qrCode, 'fallback', 'rule_unavailable', 'The smart destination rule is unavailable.');
        }

        if ($this->scheduleIsActive($qrCode, $timestamp)) {
            if ($this->isValidUrl($qrCode->scheduledUrl)) {
                return Resolution::redirect((string) $qrCode->scheduledUrl, 'scheduled');
            }

            return $this->fallbackOrMessage($qrCode, 'fallback', 'scheduled_unavailable', 'The scheduled destination is unavailable.');
        }

        if ($this->isValidUrl($qrCode->destinationUrl)) {
            return Resolution::redirect($qrCode->destinationUrl, 'primary');
        }

        return $this->fallbackOrMessage($qrCode, 'fallback', 'destination_unavailable', 'The destination is unavailable.');
    }

    public function activeScheduleLabel(QRCode $qrCode, ?int $timestamp = null): string {
        $resolution = $this->resolve($qrCode, $timestamp);
        if ($resolution->matchedRuleName) {
            return $resolution->matchedRuleName;
        }

        if (!$this->scheduleIsActive($qrCode, $timestamp ?: current_time('timestamp', true))) {
            return '-';
        }

        return DateTimeHelper::utcToDisplay($qrCode->scheduledStartAt) . ' to ' . DateTimeHelper::utcToDisplay($qrCode->scheduledEndAt);
    }

    public function ruleMatches(DestinationRule $rule, int $timestamp): bool {
        if (!$rule->isActive()) {
            return false;
        }

        if ($rule->startsAt) {
            $start = DateTimeHelper::utcTimestamp($rule->startsAt);
            if ($start && $timestamp < $start) {
                return false;
            }
        }

        if ($rule->endsAt) {
            $end = DateTimeHelper::utcTimestamp($rule->endsAt);
            if ($end && $timestamp > $end) {
                return false;
            }
        }

        $days = $rule->dayNumbers();
        if ($days) {
            $day = (int) wp_date('N', $timestamp, wp_timezone());
            if (!in_array($day, $days, true)) {
                return false;
            }
        }

        if ($rule->timeStart || $rule->timeEnd) {
            $time = wp_date('H:i', $timestamp, wp_timezone());
            if ($rule->timeStart && $time < $rule->timeStart) {
                return false;
            }
            if ($rule->timeEnd && $time > $rule->timeEnd) {
                return false;
            }
        }

        return true;
    }

    private function fallbackOrMessage(QRCode $qrCode, string $reason, string $scanStatus, string $message): Resolution {
        if ($this->isValidUrl($qrCode->fallbackUrl)) {
            return Resolution::redirect((string) $qrCode->fallbackUrl, 'fallback', $scanStatus);
        }

        return Resolution::message($reason, $scanStatus, $message);
    }

    private function scheduleIsActive(QRCode $qrCode, int $timestamp): bool {
        if (!$qrCode->scheduledUrl) {
            return false;
        }

        $start = DateTimeHelper::utcTimestamp($qrCode->scheduledStartAt);
        $end = DateTimeHelper::utcTimestamp($qrCode->scheduledEndAt);

        if (!$start || !$end || $end < $start) {
            return false;
        }

        return $timestamp >= $start && $timestamp <= $end;
    }

    private function isValidUrl(?string $url): bool {
        $url = trim((string) $url);

        return $url !== '' && (bool) wp_http_validate_url($url);
    }
}
