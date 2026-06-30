<?php

namespace QRBuzz\QR;

class QRGenerator
{
    public function generate(string $url): string
    {
        $encoded = urlencode($url);

        // Simple QR generator (no library yet for v0.1.0)
        return "https://chart.googleapis.com/chart?chs=300x300&cht=qr&chl={$encoded}";
    }
}
