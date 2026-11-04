<?php

declare(strict_types=1);

namespace RunnerDeck\Presentation;

use RunnerDeck\Configuration\Config;

final class Banner
{
    private const REPO_URL = 'https://github.com/AnikethTS/RunnerDeck';

    private const LOGO = [
        '          ######',
        '      ##############',
        '  ########      ###### @',
        '#######     @@@    # @@@@@',
        '####    @@@@@@@     @@@@ #',
        '####    @@@@@@    @@@@  ##',
        '####  @@@@@  @  @@@@@ ####',
        '###@@@@@@ @@@ @@@@@   ####',
        '#@@@@@@   @@@@@@@     ####',
        '@@@@@#      @@@     ######',
        '  @#######      ########',
        '      ##############',
        '          ######',
    ];

    private const LOGO_WIDTH = 28;

    public static function render(bool $color): string
    {
        $paint = static fn (string $code, string $text): string => $color ? "\033[{$code}m{$text}\033[0m" : $text;

        $text = [
            3 => $paint('1', 'Welcome to RunnerDeck') . ' ' . $paint('33', 'v' . Config::version()),
            4 => 'One dashboard for every self-hosted',
            5 => 'GitHub Actions runner.',
            7 => $paint('90', 'Built by') . ' Aniketh TS and contributors',
            8 => $paint('90', 'License') . '  MIT',
            10 => $paint('90', 'Docs and issues'),
            11 => $paint('4', self::REPO_URL),
        ];

        $body = [];
        foreach (self::LOGO as $i => $line) {
            $left = str_pad($line, self::LOGO_WIDTH);
            if ($color) {
                $left = (string) preg_replace_callback(
                    '/#+|@+/',
                    static fn (array $m): string => $paint($m[0][0] === '@' ? '1;33' : '90', $m[0]),
                    $left
                );
            }
            $body[] = rtrim('  ' . $left . ($text[$i] ?? ''));
        }

        return implode("\n", ['', ...$body, '', '']);
    }

    public static function show(): void
    {
        if (getenv('RUNNERDECK_NO_BANNER') === '1') {
            return;
        }
        echo self::render(self::supportsColor());
    }

    private static function supportsColor(): bool
    {
        if (getenv('NO_COLOR') !== false || !defined('STDOUT') || !function_exists('stream_isatty')) {
            return false;
        }
        if (!stream_isatty(STDOUT)) {
            return false;
        }
        if (PHP_OS_FAMILY === 'Windows') {
            return function_exists('sapi_windows_vt100_support') && @sapi_windows_vt100_support(STDOUT, true);
        }
        return true;
    }
}
