<?php

declare(strict_types=1);

namespace RunnerDeck\Security;

/**
 * Byte-mode QR symbol, error correction M, versions 1–10.
 * Enough for an otpauth URI and nothing else this app encodes.
 */
final class QrCode
{
    /** @var array<int, list<array{0: int, 1: int}>> version => [blocks, data codewords] groups */
    private const BLOCKS = [
        1 => [[1, 16]],
        2 => [[1, 28]],
        3 => [[1, 44]],
        4 => [[2, 32]],
        5 => [[2, 43]],
        6 => [[4, 27]],
        7 => [[4, 31]],
        8 => [[2, 38], [2, 39]],
        9 => [[3, 36], [2, 37]],
        10 => [[4, 43], [1, 44]],
    ];

    /** @var array<int, int> error-correction codewords per block */
    private const EC_PER_BLOCK = [
        1 => 10, 2 => 16, 3 => 26, 4 => 18, 5 => 24,
        6 => 16, 7 => 18, 8 => 22, 9 => 22, 10 => 26,
    ];

    /** @var array<int, list<int>> version => alignment centers. Index 0 is version 2. */
    private const ALIGNMENT = [
        [6, 18], [6, 22], [6, 26], [6, 30], [6, 34],
        [6, 22, 38], [6, 24, 42], [6, 26, 46], [6, 28, 50],
    ];

    /** Format bits for error level M, mask 0–7. */
    private const FORMAT_M = [
        21522, 20773, 24188, 23371, 17913, 16590, 20375, 19104,
    ];

    /** Version-info bits for versions 7–10. */
    private const VERSION_INFO = [
        7 => 0x07c94,
        8 => 0x085bc,
        9 => 0x09a99,
        10 => 0x0a4d3,
    ];

    /** @var list<int>|null */
    private static ?array $exp = null;

    /** @var array<int, int>|null */
    private static ?array $log = null;

    /**
     * Dark modules, row-major, no quiet zone.
     *
     * @return list<list<bool>>
     */
    public static function modules(string $payload): array
    {
        $version = self::versionFor(strlen($payload));
        $size = 17 + 4 * $version;
        $bits = self::dataBits($payload, $version);
        $matrix = self::blank($size, $version);
        self::finders($matrix);
        self::alignment($matrix, $version);
        self::placeBits($matrix, $bits);
        $masked = self::bestMask($matrix, $size, $version);
        self::format($masked['modules'], self::FORMAT_M[$masked['mask']]);
        self::versionInfo($masked['modules'], $version);
        $out = [];
        foreach ($masked['modules'] as $row) {
            $line = [];
            foreach ($row as $cell) {
                $line[] = $cell === 1;
            }
            $out[] = $line;
        }
        return $out;
    }

    public static function svg(string $payload): string
    {
        $modules = self::modules($payload);
        $quiet = 4;
        $size = count($modules) + $quiet * 2;
        $rects = '';
        foreach ($modules as $y => $row) {
            foreach ($row as $x => $dark) {
                if ($dark) {
                    $rects .= '<rect x="' . ($x + $quiet) . '" y="' . ($y + $quiet) . '" width="1" height="1"/>';
                }
            }
        }
        return '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 ' . $size . ' ' . $size
            . '" shape-rendering="crispEdges" role="img" aria-label="Authenticator QR code">'
            . '<rect width="100%" height="100%" fill="#fff"/>'
            . '<g fill="#000">' . $rects . '</g></svg>';
    }

    /** Two module rows per terminal line, with a quiet zone. */
    public static function terminal(string $payload): string
    {
        $modules = self::modules($payload);
        $quiet = 2;
        $n = count($modules);
        $width = $n + $quiet * 2;
        $dark = static function (int $x, int $y) use ($modules, $quiet, $n): bool {
            $mx = $x - $quiet;
            $my = $y - $quiet;
            return $mx >= 0 && $my >= 0 && $mx < $n && $my < $n && $modules[$my][$mx];
        };
        $lines = [];
        for ($y = 0; $y < $width; $y += 2) {
            $line = '';
            for ($x = 0; $x < $width; $x++) {
                $top = $dark($x, $y);
                $bottom = $y + 1 < $width && $dark($x, $y + 1);
                $line .= match (true) {
                    $top && $bottom => '█',
                    $top => '▀',
                    $bottom => '▄',
                    default => ' ',
                };
            }
            $lines[] = $line;
        }
        return implode("\n", $lines);
    }

    private static function versionFor(int $bytes): int
    {
        for ($version = 1; $version <= 10; $version++) {
            $countBits = $version < 10 ? 8 : 16;
            $need = 4 + $countBits + 8 * $bytes;
            if ($need <= self::dataCodewords($version) * 8) {
                return $version;
            }
        }
        throw new \InvalidArgumentException('QR payload is too long');
    }

    private static function dataCodewords(int $version): int
    {
        $total = 0;
        foreach (self::BLOCKS[$version] as [$blocks, $data]) {
            $total += $blocks * $data;
        }
        return $total;
    }

    /** @return list<int> bits, most significant first, including remainder bits */
    private static function dataBits(string $payload, int $version): array
    {
        $countBits = $version < 10 ? 8 : 16;
        $bits = [];
        self::append($bits, 0b0100, 4);
        self::append($bits, strlen($payload), $countBits);
        $bytes = array_values(unpack('C*', $payload) ?: []);
        foreach ($bytes as $byte) {
            self::append($bits, $byte, 8);
        }
        $capacity = self::dataCodewords($version) * 8;
        $terminator = min(4, $capacity - count($bits));
        self::append($bits, 0, $terminator);
        if (count($bits) % 8 !== 0) {
            self::append($bits, 0, 8 - (count($bits) % 8));
        }
        $pad = [0b11101100, 0b00010001];
        $i = 0;
        while (count($bits) < $capacity) {
            self::append($bits, $pad[$i % 2], 8);
            $i++;
        }

        $data = [];
        for ($i = 0; $i < count($bits); $i += 8) {
            $byte = 0;
            for ($b = 0; $b < 8; $b++) {
                $byte = ($byte << 1) | $bits[$i + $b];
            }
            $data[] = $byte;
        }

        $offset = 0;
        $dataBlocks = [];
        $errorBlocks = [];
        foreach (self::BLOCKS[$version] as [$blocks, $perBlock]) {
            $ecLen = self::EC_PER_BLOCK[$version];
            for ($block = 0; $block < $blocks; $block++) {
                $slice = array_slice($data, $offset, $perBlock);
                $offset += $perBlock;
                $dataBlocks[] = $slice;
                $errorBlocks[] = self::remainder($slice, $ecLen);
            }
        }

        $stream = [];
        $longest = 0;
        foreach ($dataBlocks as $block) {
            $longest = max($longest, count($block));
        }
        for ($i = 0; $i < $longest; $i++) {
            foreach ($dataBlocks as $block) {
                if (isset($block[$i])) {
                    self::append($stream, $block[$i], 8);
                }
            }
        }
        $ecLen = self::EC_PER_BLOCK[$version];
        for ($i = 0; $i < $ecLen; $i++) {
            foreach ($errorBlocks as $block) {
                self::append($stream, $block[$i], 8);
            }
        }
        $remainderBits = in_array($version, [2, 3, 4, 5, 6], true) ? 7 : 0;
        self::append($stream, 0, $remainderBits);
        return $stream;
    }

    /** @param list<int> $bits */
    private static function append(array &$bits, int $value, int $length): void
    {
        for ($i = $length - 1; $i >= 0; $i--) {
            $bits[] = ($value >> $i) & 1;
        }
    }

    /**
     * @param list<int> $data
     * @return list<int>
     */
    private static function remainder(array $data, int $ecLen): array
    {
        self::field();
        $gen = self::generator($ecLen);
        $block = array_merge($data, array_fill(0, $ecLen, 0));
        $len = count($data);
        for ($k = 0; $k < $len; $k++) {
            $coef = $block[$k];
            if ($coef === 0) {
                continue;
            }
            for ($n = 0; $n < $ecLen; $n++) {
                $block[$k + $n + 1] ^= self::mul($coef, $gen[$n]);
            }
        }
        return array_slice($block, $len);
    }

    /** @return list<int> */
    private static function generator(int $ecLen): array
    {
        $gen = [1];
        for ($i = 0; $i < $ecLen; $i++) {
            $next = array_fill(0, count($gen) + 1, 0);
            $factor = self::$exp[$i] ?? 0;
            foreach ($gen as $j => $coef) {
                $next[$j] ^= $coef;
                $next[$j + 1] ^= self::mul($coef, $factor);
            }
            $gen = $next;
        }
        array_shift($gen);
        return array_values($gen);
    }

    private static function mul(int $a, int $b): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        return self::$exp[(self::$log[$a] ?? 0) + (self::$log[$b] ?? 0)] ?? 0;
    }

    private static function field(): void
    {
        if (self::$exp !== null) {
            return;
        }
        $exp = [];
        $log = [];
        $x = 1;
        for ($i = 0; $i < 255; $i++) {
            $exp[] = $x;
            $log[$x] = $i;
            $x <<= 1;
            if (($x & 0x100) !== 0) {
                $x ^= 0x11d;
            }
        }
        for ($i = 255; $i < 512; $i++) {
            $exp[] = $exp[$i - 255];
        }
        self::$exp = $exp;
        self::$log = $log;
    }

    /** @return array<int, array<int, int>> */
    private static function blank(int $size, int $version): array
    {
        $matrix = [];
        for ($i = 0; $i < $size; $i++) {
            $matrix[] = array_fill(0, $size, 2);
        }
        if ($version >= 7) {
            for ($i = 0; $i < 6; $i++) {
                $matrix[$i][$size - 11] = 0;
                $matrix[$i][$size - 10] = 0;
                $matrix[$i][$size - 9] = 0;
                $matrix[$size - 11][$i] = 0;
                $matrix[$size - 10][$i] = 0;
                $matrix[$size - 9][$i] = 0;
            }
        }
        for ($i = 0; $i < 9; $i++) {
            $matrix[$i][8] = 0;
            $matrix[8][$i] = 0;
        }
        for ($i = 0; $i < 8; $i++) {
            $matrix[$size - 1 - $i][8] = 0;
            $matrix[8][$size - 1 - $i] = 0;
        }
        $bit = 1;
        for ($i = 8; $i < $size - 8; $i++) {
            $matrix[$i][6] = $bit;
            $matrix[6][$i] = $bit;
            $bit ^= 1;
        }
        return $matrix;
    }

    /** @param array<int, array<int, int>> $matrix */
    private static function finders(array &$matrix): void
    {
        $size = count($matrix);
        $pattern = [
            [0, 0, 0, 0, 0, 0, 0, 0, 0],
            [0, 1, 1, 1, 1, 1, 1, 1, 0],
            [0, 1, 0, 0, 0, 0, 0, 1, 0],
            [0, 1, 0, 1, 1, 1, 0, 1, 0],
            [0, 1, 0, 1, 1, 1, 0, 1, 0],
            [0, 1, 0, 1, 1, 1, 0, 1, 0],
            [0, 1, 0, 0, 0, 0, 0, 1, 0],
            [0, 1, 1, 1, 1, 1, 1, 1, 0],
            [0, 0, 0, 0, 0, 0, 0, 0, 0],
        ];
        $corners = [[0, 0, 1, 1], [0, $size - 8, 1, 0], [$size - 8, 0, 0, 1]];
        foreach ($corners as [$row, $col, $rowOffset, $colOffset]) {
            for ($r = 0; $r < 8; $r++) {
                for ($c = 0; $c < 8; $c++) {
                    $matrix[$row + $r][$col + $c] = $pattern[$rowOffset + $r][$colOffset + $c];
                }
            }
        }
    }

    /** @param array<int, array<int, int>> $matrix */
    private static function alignment(array &$matrix, int $version): void
    {
        if ($version < 2) {
            return;
        }
        $pattern = [
            [1, 1, 1, 1, 1],
            [1, 0, 0, 0, 1],
            [1, 0, 1, 0, 1],
            [1, 0, 0, 0, 1],
            [1, 1, 1, 1, 1],
        ];
        $positions = self::ALIGNMENT[$version - 2];
        $min = $positions[0];
        $max = $positions[count($positions) - 1];
        $skip = [[$min, $min], [$min, $max], [$max, $min]];
        foreach ($positions as $y) {
            foreach ($positions as $x) {
                if (in_array([$x, $y], $skip, true)) {
                    continue;
                }
                for ($r = 0; $r < 5; $r++) {
                    for ($c = 0; $c < 5; $c++) {
                        $matrix[$x - 2 + $r][$y - 2 + $c] = $pattern[$r][$c];
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array<int, int>> $matrix
     * @param list<int> $bits
     */
    private static function placeBits(array &$matrix, array $bits): void
    {
        $size = count($matrix);
        $idx = 0;
        $total = count($bits);
        for ($col = $size - 1; $col > 0; $col -= 2) {
            $right = $col <= 6 ? $col - 1 : $col;
            for ($vertical = 0; $vertical < $size; $vertical++) {
                for ($z = 0; $z < 2; $z++) {
                    $j = $right - $z;
                    $upwards = (($right & 2) === 0) !== ($j < 6);
                    $i = $upwards ? $size - 1 - $vertical : $vertical;
                    if ($matrix[$i][$j] === 2 && $idx < $total) {
                        $matrix[$i][$j] = $bits[$idx];
                        $idx++;
                    }
                }
            }
        }
    }

    /**
     * @param array<int, array<int, int>> $matrix
     * @return array{mask: int, modules: array<int, array<int, int>>}
     */
    private static function bestMask(array $matrix, int $size, int $version): array
    {
        $function = self::blank($size, $version);
        self::finders($function);
        self::alignment($function, $version);
        $function[$size - 8][8] = 1;
        $bestScore = PHP_INT_MAX;
        $bestMask = 0;
        $best = $matrix;
        for ($mask = 0; $mask < 8; $mask++) {
            $copy = $matrix;
            for ($i = 0; $i < $size; $i++) {
                for ($j = 0; $j < $size; $j++) {
                    if ($function[$i][$j] > 1 && self::maskBit($mask, $i, $j) === 1) {
                        $copy[$i][$j] ^= 1;
                    }
                }
            }
            $score = self::penalty($copy, $size);
            if ($score < $bestScore) {
                $bestScore = $score;
                $bestMask = $mask;
                $best = $copy;
            }
        }
        return ['mask' => $bestMask, 'modules' => $best];
    }

    private static function maskBit(int $mask, int $i, int $j): int
    {
        $on = match ($mask) {
            0 => ($i + $j) % 2 === 0,
            1 => $i % 2 === 0,
            2 => $j % 3 === 0,
            3 => ($i + $j) % 3 === 0,
            4 => (intdiv($i, 2) + intdiv($j, 3)) % 2 === 0,
            5 => ($i * $j) % 2 + ($i * $j) % 3 === 0,
            6 => (($i * $j) % 2 + ($i * $j) % 3) % 2 === 0,
            default => (($i + $j) % 2 + ($i * $j) % 3) % 2 === 0,
        };
        return $on ? 1 : 0;
    }

    /** @param array<int, array<int, int>> $matrix */
    private static function penalty(array $matrix, int $size): int
    {
        $n1 = 0;
        $n2 = 0;
        $n3 = 0;
        $dark = 0;
        $last = null;
        for ($i = 0; $i < $size; $i++) {
            $column = '';
            $rowBits = '';
            for ($j = 0; $j < $size; $j++) {
                $rowBit = $matrix[$i][$j];
                $dark += $rowBit;
                $rowBits .= (string) $rowBit;
                $column .= (string) $matrix[$j][$i];
                if (
                    $last !== null
                    && $j > 0
                    && $rowBit === $matrix[$i][$j - 1]
                    && $rowBit === $last[$j]
                    && $rowBit === $last[$j - 1]
                ) {
                    $n2 += 3;
                }
            }
            $n1 += self::runPenalty($rowBits);
            $n1 += self::runPenalty($column);
            $n3 += self::finderPenalty($rowBits, $size);
            $n3 += self::finderPenalty($column, $size);
            $last = $matrix[$i];
        }
        $percent = $dark / ($size * $size);
        $n4 = 10 * (int) (abs($percent * 100 - 50) / 5);
        return $n1 + $n2 + $n3 + $n4;
    }

    private static function runPenalty(string $bits): int
    {
        $score = 0;
        $run = 1;
        $prev = $bits[0];
        $len = strlen($bits);
        for ($i = 1; $i < $len; $i++) {
            if ($bits[$i] === $prev) {
                $run++;
                continue;
            }
            if ($run >= 5) {
                $score += $run - 2;
            }
            $run = 1;
            $prev = $bits[$i];
        }
        if ($run >= 5) {
            $score += $run - 2;
        }
        return $score;
    }

    private static function finderPenalty(string $bits, int $size): int
    {
        $pattern = '1011101';
        $count = 0;
        $idx = strpos($bits, $pattern);
        while ($idx !== false) {
            $offset = $idx + 7;
            $before = substr($bits, max($idx - 4, 0), $idx - max($idx - 4, 0));
            $after = substr($bits, $offset, min(4, $size - $offset));
            $lightBefore = $before === '' || !str_contains($before, '1');
            $lightAfter = $after === '' || !str_contains($after, '1');
            if ($idx === 0 || $idx === $size - 7 || $lightBefore || $lightAfter) {
                $count += 40;
                $offset = $idx + 7;
            } else {
                $offset = $idx + 4;
            }
            $next = strpos($bits, $pattern, $offset);
            $idx = $next;
        }
        return $count;
    }

    /** @param array<int, array<int, int>> $matrix */
    private static function format(array &$matrix, int $info): void
    {
        $size = count($matrix);
        $vOffset = 0;
        $hOffset = 0;
        for ($i = 0; $i < 8; $i++) {
            $vbit = ($info >> $i) & 1;
            $hbit = ($info >> (14 - $i)) & 1;
            if ($i === 6) {
                $vOffset++;
                $hOffset = 1;
            }
            $matrix[$i + $vOffset][8] = $vbit;
            $matrix[8][$i + $hOffset] = $hbit;
            $matrix[8][$size - 1 - $i] = $vbit;
            $matrix[$size - 1 - $i][8] = $hbit;
        }
        $matrix[$size - 8][8] = 1;
    }

    /** @param array<int, array<int, int>> $matrix */
    private static function versionInfo(array &$matrix, int $version): void
    {
        if ($version < 7) {
            return;
        }
        $info = self::VERSION_INFO[$version];
        $size = count($matrix);
        for ($i = 0; $i < 6; $i++) {
            $bit1 = ($info >> ($i * 3)) & 1;
            $bit2 = ($info >> ($i * 3 + 1)) & 1;
            $bit3 = ($info >> ($i * 3 + 2)) & 1;
            $matrix[$size - 11][$i] = $bit1;
            $matrix[$size - 10][$i] = $bit2;
            $matrix[$size - 9][$i] = $bit3;
            $matrix[$i][$size - 11] = $bit1;
            $matrix[$i][$size - 10] = $bit2;
            $matrix[$i][$size - 9] = $bit3;
        }
    }
}
