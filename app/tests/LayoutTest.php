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
        $this->assertSame('', \RunnerDeck\Presentation\Layout::htmlAttr());
        $this->assertNull(\RunnerDeck\Presentation\Layout::cookieTheme());
    }

    #[RunInSeparateProcess]
    public function testHtmlAttrAcceptsLightAndDark(): void
    {
        $_COOKIE[\RunnerDeck\Presentation\Layout::THEME_COOKIE] = 'dark';
        $this->assertSame('dark', \RunnerDeck\Presentation\Layout::cookieTheme());
        $this->assertSame(' data-theme="dark"', \RunnerDeck\Presentation\Layout::htmlAttr());

        $_COOKIE[\RunnerDeck\Presentation\Layout::THEME_COOKIE] = 'light';
        $this->assertSame(' data-theme="light"', \RunnerDeck\Presentation\Layout::htmlAttr());
    }

    #[RunInSeparateProcess]
    public function testHtmlAttrIgnoresUnknownValues(): void
    {
        $_COOKIE[\RunnerDeck\Presentation\Layout::THEME_COOKIE] = 'neon';
        $this->assertNull(\RunnerDeck\Presentation\Layout::cookieTheme());
        $this->assertSame('', \RunnerDeck\Presentation\Layout::htmlAttr());
    }

    #[RunInSeparateProcess]
    public function testHtmlOpenAppliesThemeAndTitle(): void
    {
        $_COOKIE[\RunnerDeck\Presentation\Layout::THEME_COOKIE] = 'dark';
        ob_start();
        \RunnerDeck\Presentation\Layout::htmlOpen('RunnerDeck — Settings');
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
        \RunnerDeck\Presentation\Layout::topbarStart();
        \RunnerDeck\Presentation\Layout::topbarEndStart();
        \RunnerDeck\Presentation\Layout::topbarEnd();
        $html = (string) ob_get_clean();

        $this->assertStringContainsString('class="topbar-inner page-shell"', $html);
        $this->assertStringContainsString('class="topbar-lead"', $html);
        $this->assertStringContainsString('class="topbar-brand"', $html);
        $this->assertStringContainsString('href="index.php"', $html);
        $this->assertStringContainsString('class="topbar-end"', $html);
        $this->assertStringContainsString('id="btn-theme-toggle"', $html);
        $this->assertStringContainsString('id="theme-icon-sun"', $html);
        $this->assertStringContainsString('id="theme-icon-moon"', $html);
        $this->assertStringContainsString('<h1 class="topbar-name">RunnerDeck</h1>', $html);
        $this->assertStringContainsString('class="edition-tag"', $html);
        $this->assertStringContainsString('id="update-available"', $html);
        $this->assertTrue(\RunnerDeck\Presentation\Layout::hasLogo());
        $this->assertStringContainsString('src="assets/logo.png"', $html);
        $this->assertStringContainsString('class="logo"', $html);
        $this->assertStringContainsString('class="sticky-head"', $html);
        $this->assertStringEndsWith("</header>\n</div>\n", $html);
    }

    #[RunInSeparateProcess]
    public function testPageNavMarksCurrent(): void
    {
        ob_start();
        \RunnerDeck\Presentation\Layout::pageNav('history.php');
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('aria-label="Host pages"', $html);
        $this->assertStringContainsString('href="history.php"', $html);
        $this->assertStringContainsString('aria-current="page"', $html);
        $this->assertStringContainsString('href="crashes.php"', $html);
        $this->assertStringContainsString('href="outbox.php"', $html);
        $this->assertStringContainsString('href="bundles.php"', $html);
        $this->assertStringContainsString('class="page-nav-link help-hover"', $html);
        $this->assertStringContainsString('24-hour CPU', $html);
        $this->assertStringNotContainsString('class="help-tip"', $html);
    }

    #[RunInSeparateProcess]
    public function testScopeChipShowsOrgAndLabel(): void
    {
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_ORG=acme');
        putenv('RUNNERDECK_LABEL=self-hosted-runnerdeck');
        ob_start();
        \RunnerDeck\Presentation\Layout::scopeChip();
        $html = (string) ob_get_clean();
        $this->assertStringContainsString('class="org-tag"', $html);
        $this->assertStringContainsString('acme · self-hosted-runnerdeck', $html);
        putenv('RUNNERDECK_SCOPE');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_LABEL');
    }

    #[RunInSeparateProcess]
    public function testScopeChipSilentWhenUnconfigured(): void
    {
        putenv('RUNNERDECK_SCOPE=org');
        putenv('RUNNERDECK_ORG');
        putenv('RUNNERDECK_REPO');
        ob_start();
        \RunnerDeck\Presentation\Layout::scopeChip();
        $html = (string) ob_get_clean();
        $this->assertSame('', $html);
        putenv('RUNNERDECK_SCOPE');
    }

    public function testInfoIconEscapesHelpText(): void
    {
        $html = \RunnerDeck\Presentation\Layout::infoIcon('wait <script>x</script>');
        $this->assertStringContainsString('class="help-tip"', $html);
        $this->assertStringContainsString('data-help="wait &lt;script&gt;x&lt;/script&gt;"', $html);
        $this->assertStringNotContainsString('<script>x</script>', $html);
    }
}
