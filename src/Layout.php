<?php

declare(strict_types=1);

namespace RunnerDeck;

final class Layout
{
    public const THEME_COOKIE = 'runnerdeck-theme';

    public static function cookieTheme(): ?string
    {
        $value = $_COOKIE[self::THEME_COOKIE] ?? '';
        return ($value === 'dark' || $value === 'light') ? $value : null;
    }

    public static function htmlAttr(): string
    {
        $theme = self::cookieTheme();
        if ($theme === null) {
            return '';
        }
        return ' data-theme="' . htmlspecialchars($theme, ENT_QUOTES, 'UTF-8') . '"';
    }

    public static function hasLogo(): bool
    {
        return is_file(dirname(__DIR__) . '/public/assets/logo.png');
    }

    public static function htmlOpen(string $title, bool $manifest = false): void
    {
        $cssPath = dirname(__DIR__) . '/public/assets/style.css';
        $cssV = is_file($cssPath) ? (string) filemtime($cssPath) : '0';
        $safeTitle = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

        echo '<!doctype html>' . "\n";
        echo '<html lang="en"' . self::htmlAttr() . ">\n";
        echo "<head>\n";
        echo '  <meta charset="UTF-8" />' . "\n";
        echo '  <meta name="viewport" content="width=device-width, initial-scale=1.0" />' . "\n";
        echo "  <title>{$safeTitle}</title>\n";
        if ($manifest) {
            echo '  <link rel="manifest" href="assets/manifest.webmanifest" />' . "\n";
        }
        if (self::hasLogo()) {
            echo '  <link rel="icon" href="assets/logo.png" />' . "\n";
        }
        echo '  <link rel="stylesheet" href="assets/style.css?v=' . $cssV . '" />' . "\n";
        echo "</head>\n";
        echo "<body>\n";
    }

    public static function topbarStart(): void
    {
        echo '  <header class="topbar">' . "\n";
        if (self::hasLogo()) {
            echo '    <img src="assets/logo.png" alt="Logo" class="logo" />' . "\n";
        }
        echo "    <h1>RunnerDeck</h1>\n";
    }

    public static function topbarEndStart(): void
    {
        echo '    <div class="topbar-end">' . "\n";
    }

    public static function topbarEnd(): void
    {
        $theme = self::cookieTheme();
        $sunHidden = $theme === 'dark' ? ' hidden' : '';
        $moonHidden = $theme === 'dark' ? '' : ' hidden';

        echo '    <button id="btn-theme-toggle" class="btn btn-sm" type="button"'
            . ' aria-label="Toggle theme" title="Toggle theme">' . "\n";
        echo '      <svg id="theme-icon-sun" width="16" height="16" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round"' . $sunHidden . '>' . "\n";
        echo '        <circle cx="12" cy="12" r="5"></circle>' . "\n";
        echo '        <line x1="12" y1="1" x2="12" y2="3"></line>' . "\n";
        echo '        <line x1="12" y1="21" x2="12" y2="23"></line>' . "\n";
        echo '        <line x1="4.22" y1="4.22" x2="5.64" y2="5.64"></line>' . "\n";
        echo '        <line x1="18.36" y1="18.36" x2="19.78" y2="19.78"></line>' . "\n";
        echo '        <line x1="1" y1="12" x2="3" y2="12"></line>' . "\n";
        echo '        <line x1="21" y1="12" x2="23" y2="12"></line>' . "\n";
        echo '        <line x1="4.22" y1="19.78" x2="5.64" y2="18.36"></line>' . "\n";
        echo '        <line x1="18.36" y1="5.64" x2="19.78" y2="4.22"></line>' . "\n";
        echo "      </svg>\n";
        echo '      <svg id="theme-icon-moon" width="16" height="16" viewBox="0 0 24 24"'
            . ' fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"'
            . ' stroke-linejoin="round"' . $moonHidden . '>' . "\n";
        echo '        <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z"></path>' . "\n";
        echo "      </svg>\n";
        echo "    </button>\n";
        echo "    </div>\n";
        echo "  </header>\n";
    }

    /** @param list<string> $modules */
    public static function htmlClose(array $modules = []): void
    {
        $public = dirname(__DIR__) . '/public';
        foreach ($modules as $module) {
            $full = $public . '/' . $module;
            $v = is_file($full) ? (string) filemtime($full) : '0';
            $src = htmlspecialchars($module, ENT_QUOTES, 'UTF-8');
            echo '  <script type="module" src="' . $src . '?v=' . $v . '"></script>' . "\n";
        }
        echo "</body>\n</html>\n";
    }
}
