<?php
namespace QRBuzz\SmartDestinations;

use QRBuzz\Models\QRCode;
use QRBuzz\Redirect\DestinationResolver;

class DestinationSimulationService {
    private DestinationResolver $resolver;
    public function __construct(?DestinationResolver $resolver = null) { $this->resolver = $resolver ?: new DestinationResolver(); }
    public function simulate(QRCode $qr, ?string $localDateTime = null): array {
        try { $date = $localDateTime ? new \DateTimeImmutable($localDateTime, wp_timezone()) : new \DateTimeImmutable('now', wp_timezone()); }
        catch (\Exception $exception) { throw new \InvalidArgumentException('Enter a valid simulation date and time.'); }
        $resolution = $this->resolver->resolve($qr, $date->getTimestamp());
        return ['timestamp' => $date->getTimestamp(), 'tested_at' => wp_date(get_option('date_format') . ' ' . get_option('time_format'), $date->getTimestamp(), wp_timezone()), 'timezone' => wp_timezone_string(), 'resolution' => $resolution];
    }
}
