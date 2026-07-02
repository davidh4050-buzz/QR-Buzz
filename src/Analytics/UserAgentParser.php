<?php
namespace QRBuzz\Analytics;

class UserAgentParser {

    public function deviceType(string $userAgent): string {
        $userAgent = strtolower($userAgent);

        if ($userAgent === '') {
            return 'Unknown';
        }

        if (str_contains($userAgent, 'ipad') || str_contains($userAgent, 'tablet')) {
            return 'Tablet';
        }

        if (str_contains($userAgent, 'mobile') || str_contains($userAgent, 'iphone') || str_contains($userAgent, 'android')) {
            return 'Mobile';
        }

        return 'Desktop';
    }

    public function browserFamily(string $userAgent): string {
        $userAgent = strtolower($userAgent);

        if ($userAgent === '') {
            return 'Unknown';
        }

        if (str_contains($userAgent, 'edg/')) {
            return 'Edge';
        }

        if (str_contains($userAgent, 'firefox/')) {
            return 'Firefox';
        }

        if (str_contains($userAgent, 'chrome/') || str_contains($userAgent, 'crios/')) {
            return 'Chrome';
        }

        if (str_contains($userAgent, 'safari/') && !str_contains($userAgent, 'chrome/')) {
            return 'Safari';
        }

        if (str_contains($userAgent, 'msie') || str_contains($userAgent, 'trident/')) {
            return 'Internet Explorer';
        }

        return 'Other';
    }

    public function summary(string $userAgent): string {
        $device = $this->deviceType($userAgent);
        $browser = $this->browserFamily($userAgent);

        if ($device === 'Unknown' && $browser === 'Unknown') {
            return 'Unknown';
        }

        return $browser . ' on ' . $device;
    }
}
