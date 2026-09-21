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
        $this->assertSame('', \RunnerDeck\Layout::htmlAttr());
        $this->assertNull(\RunnerDeck\Layout::cookieTheme());
    }

    #[RunInSeparateProcess]
    public function testHtmlAttrAcceptsLightAndDark(): void
    {
        $_COOKIE[\RunnerDeck\Layout::THEME_COOKIE] = 'dark';
        $this->assertSame('dark', \RunnerDeck\Layout::cookieTheme());
        $this->assertSame(' data-theme="dark"', \RunnerDeck\Layout::htmlAttr());

        $_COOKIE[\RunnerDeck\Layout::THEME_COOKIE] = 'light';
        $this->assertSame(' data-theme="light"', \RunnerDeck\Layout::htmlAttr());
    }

    #[RunInSeparateProcess]
    public function testHtmlAttrIgnoresUnknownValues(): void
    {
        $_COOKIE[\RunnerDeck\Layout::THEME_COOKIE] = 'neon';
        $this->assertNull(\RunnerDeck\Layout::cookieTheme());
        $this->assertSame('', \RunnerDeck\Layout::htmlAttr());
    }

    #[RunInSeparateProcess]
    public function testHtmlOpenAppliesThemeAndTitle(): void
    {
        $_COOKIE[\RunnerDeck\Layout::THEME_COOKIE] = 'dark';
        ob_start();
        \RunnerDeck\Layout::htmlOpen('RunnerDeck — Settings');
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('<html lang="en" data-theme="dark">', $html);
        $this->assertStringContainsString('<title>RunnerDeck — Settings</title>', $html);
        $this->assertStringContainsString('assets/style.css?v=', $html);
        $this->assertStringNotContainsString('manifest.webmanifest', $html);
    }

    #[RunInSeparateProcess]
    public function testTopbarIncludesThemeToggle(): void
    {
        ob_start();
        \RunnerDeck\Layout::topbarStart();
        \RunnerDeck\Layout::topbarEndStart();
        \RunnerDeck\Layout::topbarEnd();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('class="topbar-end"', $html);
        $this->assertStringContainsString('id="btn-theme-toggle"', $html);
        $this->assertStringContainsString('id="theme-icon-sun"', $html);
        $this->assertStringContainsString('id="theme-icon-moon"', $html);
        $this->assertStringContainsString('<h1>RunnerDeck</h1>', $html);
    }
}
