<?php
namespace QRBuzz\Analytics;

use QRBuzz\Models\QRCode;
use QRBuzz\Redirect\DestinationResolver;

class QRInsightService {

    private PerQRAnalyticsService $analytics;
    private DestinationResolver $resolver;

    public function __construct(?PerQRAnalyticsService $analytics = null, ?DestinationResolver $resolver = null) {
        $this->analytics = $analytics ?: new PerQRAnalyticsService();
        $this->resolver = $resolver ?: new DestinationResolver();
    }

    public function insights(QRCode $qrCode): array {
        if (!$qrCode->isTrackable()) {
            return ['Static QR codes do not use QR Buzz tracking. To collect analytics, create a Dynamic URL QR code.'];
        }

        $stats = $this->analytics->stats($qrCode->id);
        $insights = [];

        if ((int) $stats['total_scans'] === 0) {
            $insights[] = 'This QR code has received no scans yet.';
        }

        if ((int) $stats['total_scans'] > 0 && (int) $stats['scans_today'] === (int) $stats['total_scans']) {
            $insights[] = 'This QR code was scanned for the first time today.';
        }

        if ((int) $stats['scans_last_7_days'] > (int) $stats['previous_7_days'] && (int) $stats['previous_7_days'] > 0) {
            $insights[] = 'Scans are up compared with the previous 7 days.';
        }

        if ($stats['latest_scan'] && strtotime((string) $stats['latest_scan']) < strtotime('-30 days', current_time('timestamp'))) {
            $insights[] = 'This QR code has not been scanned in 30 days.';
        }

        if ($qrCode->effectiveStatus() === 'paused') {
            $insights[] = 'This QR code is currently paused.';
        }

        if ($qrCode->effectiveStatus() === 'expired') {
            $insights[] = 'This QR code is expired.';
        }

        if ($this->resolver->activeScheduleLabel($qrCode) !== '-') {
            $insights[] = 'This QR code has a scheduled destination active.';
        }

        $devices = $this->analytics->deviceBreakdown($qrCode->id);
        if ($devices && strtolower((string) $devices[0]['label']) === 'mobile' && (int) $devices[0]['count'] > 0) {
            $insights[] = 'Most scans happen on mobile devices.';
        }

        return $insights ?: ['This QR code is active and ready to scan.'];
    }
}
