<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class ResponsiveLayoutTest extends TestCase
{
    private function css(): string
    {
        $path = dirname(__DIR__) . '/public/assets/style.css';
        $this->assertFileExists($path);
        return (string) file_get_contents($path);
    }

    public function testSharedPageGutterAndMaxWidth(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('--page-gutter:', $css);
        $this->assertStringContainsString('--page-max:', $css);
        $this->assertStringContainsString('.page-shell', $css);
        $this->assertStringContainsString('.topbar-inner', $css);
        $this->assertStringContainsString('.table-scroll', $css);
        $this->assertStringContainsString('overflow-x: clip;', $css);
        $this->assertStringContainsString('.runner-table-wrap', $css);
        $this->assertStringContainsString('min-width: 40rem;', $css);
    }

    public function testBreakpointsCoverTabletAndPhone(): void
    {
        $css = $this->css();
        $this->assertStringContainsString('@media (width <= 900px)', $css);
        $this->assertStringContainsString('@media (width <= 720px)', $css);
        $this->assertStringContainsString('@media (width <= 520px)', $css);
        $this->assertStringContainsString('.stat-grid { grid-template-columns: 1fr; }', $css);
        $this->assertStringContainsString('.history-grid { grid-template-columns: 1fr; }', $css);
        $this->assertStringContainsString('.settings-layout {', $css);
        $this->assertStringContainsString('grid-template-columns: 1fr;', $css);
    }

    public function testOpsPagesWrapWideTables(): void
    {
        foreach (['outbox.php', 'crashes.php', 'bundles.php'] as $file) {
            $html = (string) file_get_contents(dirname(__DIR__) . '/public/' . $file);
            $this->assertStringContainsString('class="table-scroll"', $html, $file);
            $this->assertStringContainsString('class="ops-table"', $html, $file);
        }
    }

    public function testDashboardBannerUsesPageShell(): void
    {
        $html = (string) file_get_contents(dirname(__DIR__) . '/public/index.php');
        $this->assertStringContainsString('id="health-banner"', $html);
        $this->assertStringContainsString('class="page-shell"', $html);
        $this->assertStringContainsString('class="footer"', $html);
        $this->assertStringContainsString('class="runner-table-wrap"', $html);
    }
}
