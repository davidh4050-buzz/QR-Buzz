<?php
namespace QRBuzz\Utils;

class DateTimeHelper {

    public static function nowUtc(): string {
        return gmdate('Y-m-d H:i:s', current_time('timestamp', true));
    }

    public static function nowLocalDisplay(): string {
        return wp_date(get_option('date_format') . ' ' . get_option('time_format'), current_time('timestamp', true));
    }

    public static function localInputToUtc(?string $value): ?string {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value, wp_timezone());
            return $date->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s');
        } catch (\Exception $exception) {
            return null;
        }
    }

    public static function utcToLocalInput(?string $value): string {
        $value = trim((string) $value);

        if ($value === '') {
            return '';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date->setTimezone(wp_timezone())->format('Y-m-d\TH:i');
        } catch (\Exception $exception) {
            return '';
        }
    }

    public static function utcToDisplay(?string $value): string {
        $value = trim((string) $value);

        if ($value === '') {
            return '-';
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return wp_date(get_option('date_format') . ' ' . get_option('time_format'), $date->getTimestamp());
        } catch (\Exception $exception) {
            return '-';
        }
    }

    public static function utcTimestamp(?string $value): ?int {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        try {
            $date = new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
            return $date->getTimestamp();
        } catch (\Exception $exception) {
            return null;
        }
    }
}
