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

        $this->assertStringContainsString('class="topbar-end"', $html);
        $this->assertStringContainsString('id="btn-theme-toggle"', $html);
        $this->assertStringContainsString('id="theme-icon-sun"', $html);
        $this->assertStringContainsString('id="theme-icon-moon"', $html);
        $this->assertStringContainsString('<h1>RunnerDeck</h1>', $html);
        $this->assertTrue(\RunnerDeck\Core\Layout::hasLogo());
        $this->assertStringContainsString('src="assets/logo.png"', $html);
        $this->assertStringContainsString('class="logo"', $html);
    }
}
