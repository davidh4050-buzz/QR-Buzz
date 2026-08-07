<?php
namespace QRBuzz\QR;

use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelHigh;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelInterface;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelLow;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelMedium;
use Endroid\QrCode\ErrorCorrectionLevel\ErrorCorrectionLevelQuartile;
use Endroid\QrCode\Logo\Logo;
use Endroid\QrCode\Logo\LogoInterface;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;
use Endroid\QrCode\Writer\SvgWriter;
use QRBuzz\QR\Design\QRDesignSettings;

class QRGenerator {

    public function generate(string $data, int $size = 300, ?QRDesignSettings $design = null): string {
        return $this->generatePngDataUri($data, $size, $design);
    }

    public function generatePngDataUri(string $data, int $size = 300, ?QRDesignSettings $design = null): string {
        $design = $design ?: QRDesignSettings::defaults();
        if ($design->usesAdvancedRendering() && class_exists(MatrixFactory::class) && extension_loaded('gd')) {
            return 'data:image/png;base64,' . base64_encode($this->renderStyledPng($data, $size, $design));
        }

        $qrCode = $this->qrCode($data, $size, $design);
        $logo = $this->logo($design, $size, 'png');

        return (new PngWriter())->write($qrCode, $logo)->getDataUri();
    }

    public function generatePng(string $data, int $size = 600, ?QRDesignSettings $design = null): string {
        $design = $design ?: QRDesignSettings::defaults();
        if ($design->usesAdvancedRendering() && class_exists(MatrixFactory::class) && extension_loaded('gd')) {
            return $this->renderStyledPng($data, $size, $design);
        }

        $qrCode = $this->qrCode($data, $size, $design);
        $logo = $this->logo($design, $size, 'png');

        return (new PngWriter())->write($qrCode, $logo)->getString();
    }

    public function generateSvg(string $data, int $size = 600, ?QRDesignSettings $design = null): string {
        if (!class_exists(SvgWriter::class)) {
            throw new \RuntimeException('SVG QR downloads are not available with the bundled QR library.');
        }

        $design = $design ?: QRDesignSettings::defaults();
        if ($design->usesAdvancedRendering() && class_exists(MatrixFactory::class)) {
            return $this->renderStyledSvg($data, $size, $design);
        }

        $qrCode = $this->qrCode($data, $size, $design);
        $logo = $this->logo($design, $size, 'svg');

        return (new SvgWriter())->write($qrCode, $logo)->getString();
    }

    public function supportsSvg(): bool {
        return class_exists(SvgWriter::class);
    }

    public function trackingUrl(string $shortcode): string {
        return home_url('/q/' . rawurlencode($shortcode));
    }

    private function qrCode(string $data, int $size, QRDesignSettings $design): QrCode {
        $data = trim($data);

        if ($data === '') {
            throw new \InvalidArgumentException('Please enter content to generate a QR code.');
        }

        if (!class_exists(QrCode::class) || !class_exists(PngWriter::class)) {
            throw new \RuntimeException('QR Buzz dependencies are missing. Run composer install before using the development copy.');
        }

        $qrCode = new QrCode($data);
        $qrCode->setEncoding(new Encoding('UTF-8'));
        $qrCode->setErrorCorrectionLevel($this->errorCorrection($design->errorCorrection));
        $qrCode->setSize($size);
        $qrCode->setMargin($design->margin);
        $qrCode->setForegroundColor($this->color($design->foregroundColor, false));
        $qrCode->setBackgroundColor($this->color($design->backgroundColor, $design->transparentBackground));

        return $qrCode;
    }

    private function errorCorrection(string $level): ErrorCorrectionLevelInterface {
        return match (strtoupper($level)) {
            'L' => new ErrorCorrectionLevelLow(),
            'M' => new ErrorCorrectionLevelMedium(),
            'Q' => new ErrorCorrectionLevelQuartile(),
            default => new ErrorCorrectionLevelHigh(),
        };
    }

    private function color(string $hex, bool $transparent): Color {
        $hex = ltrim(QRDesignSettings::sanitizeColor($hex, '#ffffff'), '#');
        $red = hexdec(substr($hex, 0, 2));
        $green = hexdec(substr($hex, 2, 2));
        $blue = hexdec(substr($hex, 4, 2));

        return new Color($red, $green, $blue, $transparent ? 127 : 0);
    }

    private function logo(QRDesignSettings $design, int $size, string $format): ?LogoInterface {
        if (!$design->hasLogo() || !function_exists('get_attached_file')) {
            return null;
        }

        $path = get_attached_file($design->logoAttachmentId);
        if (!$path || !file_exists($path)) {
            return null;
        }

        $fileType = wp_check_filetype($path);
        if ($format === 'png' && ($fileType['type'] ?? '') === 'image/svg+xml') {
            return null;
        }

        $width = max(24, (int) round($size * ($design->logoSize / 100)));

        return Logo::create($path)
            ->setResizeToWidth($width)
            ->setPunchoutBackground(true);
    }

    private function matrix(string $data, int $size, QRDesignSettings $design): object {
        return (new MatrixFactory())->create($this->qrCode($data, $size, $design));
    }

    private function renderStyledPng(string $data, int $size, QRDesignSettings $design): string {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('PNG styling requires the GD extension.');
        }

        $matrix = $this->matrix($data, $size, $design);
        $blockSize = (float) $matrix->getBlockSize();
        $blockCount = (int) $matrix->getBlockCount();
        $outerSize = (int) $matrix->getOuterSize();
        $margin = (int) $matrix->getMarginLeft();
        $captionFontSize = $this->pngCaptionFontSize($design, $outerSize);
        $captionHeight = $design->caption !== '' ? $captionFontSize + max(24, (int) round($captionFontSize * 0.8)) : 0;

        $image = imagecreatetruecolor($outerSize, $outerSize + $captionHeight);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $background = $design->transparentBackground ? $this->gdTransparentColor($image) : $this->gdColor($image, $design->backgroundColor);
        imagefilledrectangle($image, 0, 0, $outerSize, $outerSize + $captionHeight, $background);
        imagealphablending($image, true);

        $foreground = $this->gdColor($image, $design->foregroundColor);
        $finder = $this->gdColor($image, $design->finderColor ?: $design->foregroundColor);
        $finderBackground = $this->gdColor($image, $design->backgroundColor);

        for ($row = 0; $row < $blockCount; $row++) {
            for ($col = 0; $col < $blockCount; $col++) {
                if ($this->isFinderRegion($row, $col, $blockCount) || !$matrix->getBlockValue($row, $col)) {
                    continue;
                }
                $this->drawGdShape($image, $design->dotStyle, $margin + ($col * $blockSize), $margin + ($row * $blockSize), $blockSize, $foreground);
            }
        }

        $this->drawGdFinder($image, $margin, $margin, $blockSize, $design, $finder, $finderBackground);
        $this->drawGdFinder($image, $margin + (($blockCount - 7) * $blockSize), $margin, $blockSize, $design, $finder, $finderBackground);
        $this->drawGdFinder($image, $margin, $margin + (($blockCount - 7) * $blockSize), $blockSize, $design, $finder, $finderBackground);
        $this->drawPngLogo($image, $design, $outerSize, $finderBackground);
        $this->drawPngCaption($image, $design, $outerSize);

        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }

    private function renderStyledSvg(string $data, int $size, QRDesignSettings $design): string {
        $matrix = $this->matrix($data, $size, $design);
        $blockSize = (float) $matrix->getBlockSize();
        $blockCount = (int) $matrix->getBlockCount();
        $outerSize = (int) $matrix->getOuterSize();
        $margin = (int) $matrix->getMarginLeft();
        $captionFontSize = $this->pngCaptionFontSize($design, $outerSize);
        $captionHeight = $design->caption !== '' ? $captionFontSize + max(24, (int) round($captionFontSize * 0.8)) : 0;
        $width = $outerSize;
        $height = $outerSize + $captionHeight;
        $foreground = $this->svgEscape($design->foregroundColor);
        $finder = $this->svgEscape($design->finderColor ?: $design->foregroundColor);
        $background = $this->svgEscape($design->backgroundColor);

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" width="' . esc_attr((string) $width) . '" height="' . esc_attr((string) $height) . '" viewBox="0 0 ' . esc_attr((string) $width) . ' ' . esc_attr((string) $height) . '">';
        if (!$design->transparentBackground) {
            $svg .= '<rect width="100%" height="100%" fill="' . $background . '"/>';
        }

        for ($row = 0; $row < $blockCount; $row++) {
            for ($col = 0; $col < $blockCount; $col++) {
                if ($this->isFinderRegion($row, $col, $blockCount) || !$matrix->getBlockValue($row, $col)) {
                    continue;
                }
                $svg .= $this->svgShape($design->dotStyle, $margin + ($col * $blockSize), $margin + ($row * $blockSize), $blockSize, $foreground);
            }
        }

        $svg .= $this->svgFinder($margin, $margin, $blockSize, $design, $finder, $background);
        $svg .= $this->svgFinder($margin + (($blockCount - 7) * $blockSize), $margin, $blockSize, $design, $finder, $background);
        $svg .= $this->svgFinder($margin, $margin + (($blockCount - 7) * $blockSize), $blockSize, $design, $finder, $background);
        $svg .= $this->svgLogo($design, $outerSize, $background);

        if ($design->caption !== '') {
            $svg .= '<text x="' . esc_attr((string) ($outerSize / 2)) . '" y="' . esc_attr((string) ($outerSize + $captionFontSize + max(8, (int) round($captionFontSize * 0.25)))) . '" text-anchor="middle" font-family="' . esc_attr($design->captionFontCss()) . '" font-size="' . esc_attr((string) $captionFontSize) . '" fill="' . $this->svgEscape($design->captionFontColor) . '">' . $this->svgEscape($design->caption) . '</text>';
        }

        return $svg . '</svg>';
    }

    private function isFinderRegion(int $row, int $col, int $blockCount): bool {
        return ($row < 7 && $col < 7)
            || ($row < 7 && $col >= $blockCount - 7)
            || ($row >= $blockCount - 7 && $col < 7);
    }

    private function drawGdFinder($image, float $x, float $y, float $blockSize, QRDesignSettings $design, int $foreground, int $background): void {
        $this->drawGdShape($image, $design->finderStyle, $x, $y, $blockSize * 7, $foreground);
        $this->drawGdShape($image, $design->finderStyle, $x + $blockSize, $y + $blockSize, $blockSize * 5, $background);
        $this->drawGdShape($image, $design->finderDotStyle, $x + ($blockSize * 2), $y + ($blockSize * 2), $blockSize * 3, $foreground);
    }

    private function drawGdShape($image, string $shape, float $x, float $y, float $size, int $color): void {
        $x1 = (int) floor($x);
        $y1 = (int) floor($y);
        $x2 = (int) ceil($x + $size);
        $y2 = (int) ceil($y + $size);

        if (in_array($shape, ['dot', 'circle'], true)) {
            imagefilledellipse($image, (int) round($x + ($size / 2)), (int) round($y + ($size / 2)), (int) ceil($size), (int) ceil($size), $color);
            return;
        }

        if ($shape === 'rounded') {
            $this->drawGdRoundedRect($image, $x1, $y1, $x2, $y2, max(2, (int) round($size * 0.28)), $color);
            return;
        }

        imagefilledrectangle($image, $x1, $y1, $x2, $y2, $color);
    }

    private function drawGdRoundedRect($image, int $x1, int $y1, int $x2, int $y2, int $radius, int $color): void {
        imagefilledrectangle($image, $x1 + $radius, $y1, $x2 - $radius, $y2, $color);
        imagefilledrectangle($image, $x1, $y1 + $radius, $x2, $y2 - $radius, $color);
        imagefilledellipse($image, $x1 + $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y1 + $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x1 + $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
        imagefilledellipse($image, $x2 - $radius, $y2 - $radius, $radius * 2, $radius * 2, $color);
    }

    private function drawPngLogo($image, QRDesignSettings $design, int $outerSize, int $background): void {
        $logo = $this->gdLogoImage($design);
        if (!$logo) {
            return;
        }

        $boxSize = max(24, (int) round($outerSize * ($design->logoSize / 100)));
        $sourceWidth = imagesx($logo);
        $sourceHeight = imagesy($logo);
        $ratio = $sourceWidth > 0 && $sourceHeight > 0 ? min($boxSize / $sourceWidth, $boxSize / $sourceHeight) : 1;
        $logoWidth = max(1, (int) round($sourceWidth * $ratio));
        $logoHeight = max(1, (int) round($sourceHeight * $ratio));
        $boxX = (int) round(($outerSize - $boxSize) / 2);
        $boxY = (int) round(($outerSize - $boxSize) / 2);
        $x = (int) round(($outerSize - $logoWidth) / 2);
        $y = (int) round(($outerSize - $logoHeight) / 2);
        $padding = max(4, (int) round($boxSize * 0.12));
        imagefilledrectangle($image, $boxX - $padding, $boxY - $padding, $boxX + $boxSize + $padding, $boxY + $boxSize + $padding, $background);
        imagecopyresampled($image, $logo, $x, $y, 0, 0, $logoWidth, $logoHeight, $sourceWidth, $sourceHeight);
        imagedestroy($logo);
    }

    private function drawPngCaption($image, QRDesignSettings $design, int $outerSize): void {
        if ($design->caption === '') {
            return;
        }

        $maxWidth = $outerSize - max(20, (int) round($outerSize * 0.08));
        $fontSize = $this->fitPngCaptionFontSize($design, $outerSize, $maxWidth);
        $fontPath = $this->captionFontPath($design->captionFontFamily);
        $color = $this->gdColor($image, $design->captionFontColor);

        if ($fontPath && function_exists('imagettfbbox')) {
            $box = imagettfbbox($fontSize, 0, $fontPath, $design->caption);
            $textWidth = abs((int) $box[2] - (int) $box[0]);
            $x = max(10, (int) round(($outerSize - $textWidth) / 2));
            $y = $outerSize + max($fontSize + 8, (int) round($fontSize * 1.25));
            imagettftext($image, $fontSize, 0, $x, $y, $color, $fontPath, $design->caption);
            return;
        }

        $this->drawScaledBuiltInCaption($image, $design, $outerSize, $fontSize, $maxWidth);
    }

    private function gdLogoImage(QRDesignSettings $design) {
        if (!$design->hasLogo() || !function_exists('get_attached_file')) {
            return null;
        }

        $path = get_attached_file($design->logoAttachmentId);
        if (!$path || !file_exists($path)) {
            return null;
        }

        $type = wp_check_filetype($path);
        return match ($type['type'] ?? '') {
            'image/png' => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($path) : null,
            default => null,
        };
    }

    private function svgLogo(QRDesignSettings $design, int $outerSize, string $background): string {
        if (!$design->hasLogo() || !function_exists('get_attached_file')) {
            return '';
        }

        $path = get_attached_file($design->logoAttachmentId);
        if (!$path || !file_exists($path)) {
            return '';
        }

        $type = wp_check_filetype($path);
        $mime = (string) ($type['type'] ?? '');
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/svg+xml', 'image/webp'], true)) {
            return '';
        }

        $logoWidth = max(24, (int) round($outerSize * ($design->logoSize / 100)));
        $x = (int) round(($outerSize - $logoWidth) / 2);
        $y = (int) round(($outerSize - $logoWidth) / 2);
        $padding = max(4, (int) round($logoWidth * 0.12));
        $data = base64_encode((string) file_get_contents($path));
        return '<rect x="' . esc_attr((string) ($x - $padding)) . '" y="' . esc_attr((string) ($y - $padding)) . '" width="' . esc_attr((string) ($logoWidth + ($padding * 2))) . '" height="' . esc_attr((string) ($logoWidth + ($padding * 2))) . '" fill="' . $background . '"/><image href="data:' . esc_attr($mime) . ';base64,' . esc_attr($data) . '" x="' . esc_attr((string) $x) . '" y="' . esc_attr((string) $y) . '" width="' . esc_attr((string) $logoWidth) . '" height="' . esc_attr((string) $logoWidth) . '" preserveAspectRatio="xMidYMid meet"/>';
    }

    private function svgFinder(float $x, float $y, float $blockSize, QRDesignSettings $design, string $foreground, string $background): string {
        return $this->svgShape($design->finderStyle, $x, $y, $blockSize * 7, $foreground)
            . $this->svgShape($design->finderStyle, $x + $blockSize, $y + $blockSize, $blockSize * 5, $background)
            . $this->svgShape($design->finderDotStyle, $x + ($blockSize * 2), $y + ($blockSize * 2), $blockSize * 3, $foreground);
    }

    private function svgShape(string $shape, float $x, float $y, float $size, string $color): string {
        $x = round($x, 3);
        $y = round($y, 3);
        $size = round($size, 3);
        if (in_array($shape, ['dot', 'circle'], true)) {
            $center = $size / 2;
            return '<circle cx="' . esc_attr((string) ($x + $center)) . '" cy="' . esc_attr((string) ($y + $center)) . '" r="' . esc_attr((string) $center) . '" fill="' . $color . '"/>';
        }
        $radius = $shape === 'rounded' ? max(1, round($size * 0.28, 3)) : 0;
        return '<rect x="' . esc_attr((string) $x) . '" y="' . esc_attr((string) $y) . '" width="' . esc_attr((string) $size) . '" height="' . esc_attr((string) $size) . '" rx="' . esc_attr((string) $radius) . '" fill="' . $color . '"/>';
    }

    private function gdColor($image, string $hex): int {
        [$red, $green, $blue] = $this->rgb($hex);
        return imagecolorallocatealpha($image, $red, $green, $blue, 0);
    }

    private function gdTransparentColor($image): int {
        return imagecolorallocatealpha($image, 255, 255, 255, 127);
    }

    private function rgb(string $hex): array {
        $hex = ltrim(QRDesignSettings::sanitizeColor($hex, '#ffffff'), '#');
        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private function svgEscape(string $value): string {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }

    private function truncateForBuiltInFont(string $text, int $font, int $maxWidth): string {
        $ellipsis = '...';
        while (imagefontwidth($font) * strlen($text) > $maxWidth && strlen($text) > strlen($ellipsis)) {
            $text = substr($text, 0, -1);
        }
        return strlen($text) < strlen($ellipsis) || imagefontwidth($font) * strlen($text) <= $maxWidth ? $text : substr($text, 0, -strlen($ellipsis)) . $ellipsis;
    }

    private function pngCaptionFontSize(QRDesignSettings $design, int $outerSize): int {
        return max($design->captionFontSize, (int) round($outerSize * 0.055));
    }

    private function fitPngCaptionFontSize(QRDesignSettings $design, int $outerSize, int $maxWidth): int {
        $fontSize = $this->pngCaptionFontSize($design, $outerSize);
        $fontPath = $this->captionFontPath($design->captionFontFamily);
        if (!$fontPath || !function_exists('imagettfbbox')) {
            return $fontSize;
        }

        while ($fontSize > 8) {
            $box = imagettfbbox($fontSize, 0, $fontPath, $design->caption);
            $textWidth = abs((int) $box[2] - (int) $box[0]);
            if ($textWidth <= $maxWidth) {
                break;
            }
            $fontSize--;
        }
        return $fontSize;
    }

    private function drawScaledBuiltInCaption($image, QRDesignSettings $design, int $outerSize, int $targetFontSize, int $maxWidth): void {
        $font = 5;
        $caption = $this->truncateForBuiltInFont($design->caption, $font, $maxWidth);
        $sourceWidth = max(1, imagefontwidth($font) * strlen($caption));
        $sourceHeight = imagefontheight($font);
        $scale = max(1, min($maxWidth / $sourceWidth, $targetFontSize / $sourceHeight));
        $targetWidth = (int) round($sourceWidth * $scale);
        $targetHeight = (int) round($sourceHeight * $scale);
        $textImage = imagecreatetruecolor($sourceWidth, $sourceHeight);
        imagealphablending($textImage, false);
        imagesavealpha($textImage, true);
        imagefilledrectangle($textImage, 0, 0, $sourceWidth, $sourceHeight, $this->gdTransparentColor($textImage));
        imagealphablending($textImage, true);
        imagestring($textImage, $font, 0, 0, $caption, $this->gdColor($textImage, $design->captionFontColor));
        imagecopyresampled($image, $textImage, (int) round(($outerSize - $targetWidth) / 2), $outerSize + max(8, (int) round($targetFontSize * 0.3)), 0, 0, $targetWidth, $targetHeight, $sourceWidth, $sourceHeight);
        imagedestroy($textImage);
    }

    private function captionFontPath(string $family): ?string {
        $candidates = match ($family) {
            'georgia' => ['/usr/share/fonts/truetype/dejavu/DejaVuSerif.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSerif-Regular.ttf'],
            'verdana', 'trebuchet', 'arial' => ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf', '/usr/share/fonts/truetype/liberation2/LiberationSans-Regular.ttf'],
            'courier' => ['/usr/share/fonts/truetype/dejavu/DejaVuSansMono.ttf', '/usr/share/fonts/truetype/liberation2/LiberationMono-Regular.ttf'],
            default => ['/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf'],
        };
        foreach ($candidates as $path) {
            if (is_readable($path)) {
                return $path;
            }
        }
        return null;
    }
}
