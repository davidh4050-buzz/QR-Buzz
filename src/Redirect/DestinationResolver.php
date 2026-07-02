<?php
namespace QRBuzz\Redirect;

use QRBuzz\Models\QRCode;
use QRBuzz\Utils\DateTimeHelper;

class DestinationResolver {

    public function resolve(QRCode $qrCode, ?int $timestamp = null): Resolution {
        $timestamp = $timestamp ?: current_time('timestamp', true);

        if ($qrCode->isExpired($timestamp)) {
            return $this->fallbackOrMessage($qrCode, 'expired', 'expired', 'This QR code has expired.');
        }

        if ($qrCode->status === 'paused' || !$qrCode->active) {
            return $this->fallbackOrMessage($qrCode, 'paused', 'paused', 'This QR code is currently paused.');
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
        if (!$this->scheduleIsActive($qrCode, $timestamp ?: current_time('timestamp', true))) {
            return '-';
        }

        return DateTimeHelper::utcToDisplay($qrCode->scheduledStartAt) . ' to ' . DateTimeHelper::utcToDisplay($qrCode->scheduledEndAt);
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
