<?php
namespace QRBuzz\QR;

class QRGenerator {

    public function generate(string $url): string {
        $data = trim($url);

        if ($data === '') {
            throw new \InvalidArgumentException('Please enter a URL to generate a QR code.');
        }

        $version = $this->selectVersion(strlen($data));
        $matrix = $this->createMatrix($data, $version);

        return $this->matrixToSvgDataUri($matrix);
    }

    private function selectVersion(int $length): int {
        foreach ([1 => 14, 2 => 26, 3 => 42] as $version => $capacity) {
            if ($length <= $capacity) {
                return $version;
            }
        }

        throw new \InvalidArgumentException('This local generator supports URLs up to 42 characters in v0.2.0.');
    }

    private function createMatrix(string $data, int $version): array {
        $size = 21 + (($version - 1) * 4);
        $modules = array_fill(0, $size, array_fill(0, $size, false));
        $reserved = array_fill(0, $size, array_fill(0, $size, false));

        $this->drawFunctionPatterns($modules, $reserved, $version);
        $this->drawCodewords($modules, $reserved, $this->makeCodewords($data, $version));
        $this->applyMask($modules, $reserved);
        $this->drawFormatBits($modules, $reserved);

        return $modules;
    }

    private function makeCodewords(string $data, int $version): array {
        $settings = [
            1 => ['data' => 16, 'ecc' => 10],
            2 => ['data' => 28, 'ecc' => 16],
            3 => ['data' => 44, 'ecc' => 26],
        ];
        $dataCodewords = $settings[$version]['data'];
        $bits = '0100' . $this->toBits(strlen($data), 8);

        foreach (unpack('C*', $data) as $byte) {
            $bits .= $this->toBits($byte, 8);
        }

        $capacityBits = $dataCodewords * 8;
        $bits .= str_repeat('0', min(4, $capacityBits - strlen($bits)));
        $bits .= str_repeat('0', (8 - (strlen($bits) % 8)) % 8);

        $codewords = [];
        for ($i = 0; $i < strlen($bits); $i += 8) {
            $codewords[] = bindec(substr($bits, $i, 8));
        }

        for ($i = 0, $pads = [0xEC, 0x11]; count($codewords) < $dataCodewords; $i++) {
            $codewords[] = $pads[$i % 2];
        }

        return array_merge($codewords, $this->calculateEcc($codewords, $settings[$version]['ecc']));
    }

    private function drawFunctionPatterns(array &$modules, array &$reserved, int $version): void {
        $size = count($modules);
        $this->drawFinder($modules, $reserved, 3, 3);
        $this->drawFinder($modules, $reserved, $size - 4, 3);
        $this->drawFinder($modules, $reserved, 3, $size - 4);

        for ($i = 0; $i < $size; $i++) {
            if (!$reserved[6][$i]) {
                $modules[6][$i] = $i % 2 === 0;
            }
            if (!$reserved[$i][6]) {
                $modules[$i][6] = $i % 2 === 0;
            }
            $this->setReserved($reserved, $i, 6);
            $this->setReserved($reserved, 6, $i);
        }

        if ($version > 1) {
            $this->drawAlignment($modules, $reserved, $size - 7, $size - 7);
        }

        $this->setModule($modules, $reserved, 8, $size - 8, true);

        for ($i = 0; $i < 9; $i++) {
            $this->setReserved($reserved, 8, $i);
            $this->setReserved($reserved, $i, 8);
        }
        for ($i = $size - 8; $i < $size; $i++) {
            $this->setReserved($reserved, 8, $i);
            $this->setReserved($reserved, $i, 8);
        }
    }

    private function drawFinder(array &$modules, array &$reserved, int $centerX, int $centerY): void {
        $size = count($modules);

        for ($dy = -4; $dy <= 4; $dy++) {
            for ($dx = -4; $dx <= 4; $dx++) {
                $x = $centerX + $dx;
                $y = $centerY + $dy;
                if ($x < 0 || $y < 0 || $x >= $size || $y >= $size) {
                    continue;
                }
                $distance = max(abs($dx), abs($dy));
                $modules[$y][$x] = $distance !== 2 && $distance !== 4;
                $reserved[$y][$x] = true;
            }
        }
    }

    private function drawAlignment(array &$modules, array &$reserved, int $centerX, int $centerY): void {
        for ($dy = -2; $dy <= 2; $dy++) {
            for ($dx = -2; $dx <= 2; $dx++) {
                $distance = max(abs($dx), abs($dy));
                $modules[$centerY + $dy][$centerX + $dx] = $distance !== 1;
                $reserved[$centerY + $dy][$centerX + $dx] = true;
            }
        }
    }

    private function drawCodewords(array &$modules, array $reserved, array $codewords): void {
        $size = count($modules);
        $bits = '';
        foreach ($codewords as $codeword) {
            $bits .= $this->toBits($codeword, 8);
        }

        $bitIndex = 0;
        $direction = -1;
        for ($right = $size - 1; $right >= 1; $right -= 2) {
            if ($right === 6) {
                $right--;
            }
            for ($vert = 0; $vert < $size; $vert++) {
                $y = $direction === 1 ? $vert : $size - 1 - $vert;
                for ($j = 0; $j < 2; $j++) {
                    $x = $right - $j;
                    if ($reserved[$y][$x]) {
                        continue;
                    }
                    $modules[$y][$x] = $bitIndex < strlen($bits) && $bits[$bitIndex] === '1';
                    $bitIndex++;
                }
            }
            $direction *= -1;
        }
    }

    private function applyMask(array &$modules, array $reserved): void {
        $size = count($modules);
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                if (!$reserved[$y][$x] && (($x + $y) % 2 === 0)) {
                    $modules[$y][$x] = !$modules[$y][$x];
                }
            }
        }
    }

    private function drawFormatBits(array &$modules, array &$reserved): void {
        $size = count($modules);
        $format = 0x5412; // Error correction level M, mask pattern 0.

        for ($i = 0; $i <= 5; $i++) {
            $this->setModule($modules, $reserved, 8, $i, (($format >> $i) & 1) === 1);
        }
        $this->setModule($modules, $reserved, 8, 7, (($format >> 6) & 1) === 1);
        $this->setModule($modules, $reserved, 8, 8, (($format >> 7) & 1) === 1);
        $this->setModule($modules, $reserved, 7, 8, (($format >> 8) & 1) === 1);

        for ($i = 9; $i < 15; $i++) {
            $this->setModule($modules, $reserved, 14 - $i, 8, (($format >> $i) & 1) === 1);
        }
        for ($i = 0; $i < 8; $i++) {
            $this->setModule($modules, $reserved, $size - 1 - $i, 8, (($format >> $i) & 1) === 1);
        }
        for ($i = 8; $i < 15; $i++) {
            $this->setModule($modules, $reserved, 8, $size - 15 + $i, (($format >> $i) & 1) === 1);
        }
    }

    private function calculateEcc(array $data, int $degree): array {
        $generator = $this->reedSolomonGenerator($degree);
        $remainder = array_fill(0, $degree, 0);

        foreach ($data as $byte) {
            $factor = $byte ^ array_shift($remainder);
            $remainder[] = 0;
            for ($i = 0; $i < $degree; $i++) {
                $remainder[$i] ^= $this->gfMultiply($generator[$i], $factor);
            }
        }

        return $remainder;
    }

    private function reedSolomonGenerator(int $degree): array {
        $result = array_fill(0, $degree, 0);
        $result[$degree - 1] = 1;
        $root = 1;

        for ($i = 0; $i < $degree; $i++) {
            for ($j = 0; $j < $degree; $j++) {
                $result[$j] = $this->gfMultiply($result[$j], $root);
                if ($j + 1 < $degree) {
                    $result[$j] ^= $result[$j + 1];
                }
            }
            $root = $this->gfMultiply($root, 0x02);
        }

        return $result;
    }

    private function gfMultiply(int $x, int $y): int {
        $result = 0;
        for ($i = 7; $i >= 0; $i--) {
            $result = ($result << 1) ^ ((($result >> 7) & 1) * 0x11D);
            $result ^= (($y >> $i) & 1) * $x;
        }
        return $result & 0xFF;
    }

    private function matrixToSvgDataUri(array $matrix): string {
        $quietZone = 4;
        $moduleSize = 10;
        $matrixSize = count($matrix);
        $size = ($matrixSize + ($quietZone * 2)) * $moduleSize;
        $paths = [];

        foreach ($matrix as $y => $row) {
            foreach ($row as $x => $isDark) {
                if ($isDark) {
                    $paths[] = 'M' . (($x + $quietZone) * $moduleSize) . ' ' . (($y + $quietZone) * $moduleSize) . 'h' . $moduleSize . 'v' . $moduleSize . 'h-' . $moduleSize . 'z';
                }
            }
        }

        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size . '" width="' . $size . '" height="' . $size . '" shape-rendering="crispEdges"><rect width="100%" height="100%" fill="#fff"/><path fill="#000" d="' . implode('', $paths) . '"/></svg>';

        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    private function setModule(array &$modules, array &$reserved, int $x, int $y, bool $value): void {
        $modules[$y][$x] = $value;
        $reserved[$y][$x] = true;
    }

    private function setReserved(array &$reserved, int $x, int $y): void {
        if ($x >= 0 && $y >= 0 && $y < count($reserved) && $x < count($reserved)) {
            $reserved[$y][$x] = true;
        }
    }

    private function toBits(int $value, int $length): string {
        return str_pad(decbin($value), $length, '0', STR_PAD_LEFT);
    }
}
