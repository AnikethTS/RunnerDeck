<?php

declare(strict_types=1);

namespace RunnerDeck\Core;

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
        return is_file(dirname(__DIR__, 2) . '/public/assets/logo.png');
    }

    public static function htmlOpen(string $title, bool $manifest = false): void
    {
        $cssPath = dirname(__DIR__, 2) . '/public/assets/style.css';
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
        echo '    <div class="topbar-inner page-shell">' . "\n";
        if (self::hasLogo()) {
            echo '      <img src="assets/logo.png" alt="Logo" class="logo" />' . "\n";
        }
        echo "      <h1>RunnerDeck</h1>\n";
    }

    public static function topbarEndStart(): void
    {
        echo '    <div class="topbar-end">' . "\n";
    }

    public static function pageNav(string $current): void
    {
        $links = [
            'index.php' => [
                'Dashboard',
                'This host’s runner pool, GitHub jobs, and start/stop.',
            ],
            'history.php' => [
                'History',
                '24-hour CPU, memory, and pool disk-free charts from local samples.',
            ],
            'crashes.php' => [
                'Crashes',
                'Seven UTC days of crash counts and the recent crash list. No GitHub.',
            ],
            'outbox.php' => [
                'Outbox',
                'Failed crash-webhook posts waiting to retry. This page does not send.',
            ],
            'bundles.php' => [
                'Bundles',
                'Daily host-bundle JSON files. Download or delete. TOTP is never included.',
            ],
            'settings.php' => [
                'Settings',
                'Org/repo, automation, webhook, GitHub App, login, and host copy.',
            ],
        ];
        echo '      <nav class="page-nav" aria-label="Host pages">' . "\n";
        foreach ($links as $href => [$label, $help]) {
            $currentAttr = $current === $href ? ' aria-current="page"' : '';
            echo '        <span class="page-nav-item">'
                . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                . '" class="page-nav-link"' . $currentAttr . '>'
                . htmlspecialchars($label, ENT_QUOTES, 'UTF-8') . '</a>'
                . self::infoIcon($help) . "</span>\n";
        }
        echo "      </nav>\n";
    }

    /** Hover card text for toolbar buttons (CSS ::after). */
    public static function hoverHelp(string $text): string
    {
        return ' data-help="' . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '"';
    }

    public static function infoIcon(string $text): string
    {
        $e = htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
        return '<button type="button" class="help-tip" data-help="' . $e
            . '" aria-label="' . $e . '">i</button>';
    }

    public static function logoutForm(string $csrfToken): void
    {
        if (!Auth::isEnabled()) {
            return;
        }
        echo '      <form method="post" action="logout.php" class="logout-form">' . "\n";
        echo '        <input type="hidden" name="csrf_token" value="'
            . htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') . '" />' . "\n";
        echo '        <button type="submit" class="btn btn-sm help-hover"'
            . self::hoverHelp('End this TOTP session.') . '>Log out</button>' . "\n";
        echo "      </form>\n";
    }

    public static function topbarEnd(): void
    {
        $theme = self::cookieTheme();
        $sunHidden = $theme === 'dark' ? ' hidden' : '';
        $moonHidden = $theme === 'dark' ? '' : ' hidden';

        echo '    <button id="btn-theme-toggle" class="btn btn-sm help-hover" type="button"'
            . ' aria-label="Toggle theme"'
            . self::hoverHelp('Switch between light and dark. Saved in a cookie.') . '>' . "\n";
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
        echo "    </div>\n";
        echo "  </header>\n";
    }

    /** @param list<string> $modules */
    public static function htmlClose(array $modules = []): void
    {
        $public = dirname(__DIR__, 2) . '/public';
        foreach ($modules as $module) {
            $full = $public . '/' . $module;
            $v = is_file($full) ? (string) filemtime($full) : '0';
            $src = htmlspecialchars($module, ENT_QUOTES, 'UTF-8');
            echo '  <script type="module" src="' . $src . '?v=' . $v . '"'
                . SecurityHeaders::nonceAttr() . '></script>' . "\n";
        }
        echo "</body>\n</html>\n";
    }
}
