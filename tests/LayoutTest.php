<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class LayoutTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testHtmlAttrEmptyWithoutCookie(): void
    {
        $_COOKIE = [];
        $this->assertSame('', \RunnerDeck\Core\Layout::htmlAttr());
        $this->assertNull(\RunnerDeck\Core\Layout::cookieTheme());
    }

    #[RunInSeparateProcess]
    public function testHtmlAttrAcceptsLightAndDark(): void
    {
        $_COOKIE[\RunnerDeck\Core\Layout::THEME_COOKIE] = 'dark';
        $this->assertSame('dark', \RunnerDeck\Core\Layout::cookieTheme());
        $this->assertSame(' data-theme="dark"', \RunnerDeck\Core\Layout::htmlAttr());

        $_COOKIE[\RunnerDeck\Core\Layout::THEME_COOKIE] = 'light';
        $this->assertSame(' data-theme="light"', \RunnerDeck\Core\Layout::htmlAttr());
    }

    #[RunInSeparateProcess]
    public function testHtmlAttrIgnoresUnknownValues(): void
    {
        $_COOKIE[\RunnerDeck\Core\Layout::THEME_COOKIE] = 'neon';
        $this->assertNull(\RunnerDeck\Core\Layout::cookieTheme());
        $this->assertSame('', \RunnerDeck\Core\Layout::htmlAttr());
    }

    #[RunInSeparateProcess]
    public function testHtmlOpenAppliesThemeAndTitle(): void
    {
        $_COOKIE[\RunnerDeck\Core\Layout::THEME_COOKIE] = 'dark';
        ob_start();
        \RunnerDeck\Core\Layout::htmlOpen('RunnerDeck — Settings');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<html lang="en" data-theme="dark">', $html);
        $this->assertStringContainsString('<title>RunnerDeck — Settings</title>', $html);
        $this->assertStringContainsString(
            'name="viewport" content="width=device-width, initial-scale=1.0"',
            $html
        );
        $this->assertStringContainsString('assets/style.css?v=', $html);
        $this->assertStringContainsString('rel="icon" href="assets/logo.png"', $html);
        $this->assertStringNotContainsString('manifest.webmanifest', $html);
    }

    #[RunInSeparateProcess]
    public function testTopbarIncludesThemeToggle(): void
    {
        ob_start();
        \RunnerDeck\Core\Layout::topbarStart();
        \RunnerDeck\Core\Layout::topbarEndStart();
        \RunnerDeck\Core\Layout::topbarEnd();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('class="topbar-inner page-shell"', $html);
        $this->assertStringContainsString('class="topbar-end"', $html);
        $this->assertStringContainsString('id="btn-theme-toggle"', $html);
        $this->assertStringContainsString('id="theme-icon-sun"', $html);
        $this->assertStringContainsString('id="theme-icon-moon"', $html);
        $this->assertStringContainsString('<h1>RunnerDeck</h1>', $html);
        $this->assertTrue(\RunnerDeck\Core\Layout::hasLogo());
        $this->assertStringContainsString('src="assets/logo.png"', $html);
        $this->assertStringContainsString('class="logo"', $html);
    }

    #[RunInSeparateProcess]
    public function testPageNavMarksCurrent(): void
    {
        ob_start();
        \RunnerDeck\Core\Layout::pageNav('history.php');
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('aria-label="Host pages"', $html);
        $this->assertStringContainsString('href="history.php"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('href="crashes.php"', $html);
        $this->assertStringContainsString('href="outbox.php"', $html);
        $this->assertStringContainsString('href="bundles.php"', $html);
        $this->assertStringContainsString('class="help-tip"', $html);
        $this->assertStringContainsString('24-hour CPU', $html);
    }

    public function testInfoIconEscapesHelpText(): void
    {
        $html = \RunnerDeck\Core\Layout::infoIcon('wait <script>x</script>');
        $this->assertStringContainsString('class="help-tip"', $html);
        $this->assertStringContainsString('data-help="wait &lt;script&gt;x&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }
}
