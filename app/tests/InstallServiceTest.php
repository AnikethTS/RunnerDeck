<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\TestCase;

final class InstallServiceTest extends TestCase
{
    private function repo(): string
    {
        return dirname(__DIR__, 2);
    }

    /** @param list<string> $args
     *  @param array<string, string> $extra
     *  @return array{0: int, 1: string, 2: string}
     */
    private function launch(array $args, array $extra = []): array
    {
        $env = getenv();
        foreach ($extra as $key => $value) {
            $env[$key] = $value;
        }
        $cmd = array_merge(['bash', $this->repo() . '/bin/install-service.sh'], $args);
        $spec = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $proc = proc_open($cmd, $spec, $pipes, $this->repo(), $env);
        $this->assertIsResource($proc);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return [proc_close($proc), is_string($out) ? $out : '', is_string($err) ? $err : ''];
    }

    public function testLinuxPrintUsesThisCheckoutAndDoesNotInstall(): void
    {
        $home = sys_get_temp_dir() . '/runnerdeck-svc-' . bin2hex(random_bytes(4));
        mkdir($home);
        [$code, $out, $err] = $this->launch(['--port', '8123'], [
            'RUNNERDECK_SERVICE_OS' => 'Linux',
            'HOME' => $home,
        ]);

        $this->assertSame(0, $code, $err);
        $user = posix_getpwuid(posix_geteuid());
        $this->assertIsArray($user);
        $this->assertStringContainsString('User=' . $user['name'], $out);
        $this->assertStringContainsString('WorkingDirectory=' . $this->repo(), $out);
        $this->assertStringContainsString('ExecStart=' . $this->repo() . '/run.sh 8123', $out);
        $this->assertStringContainsString('WantedBy=multi-user.target', $out);
        $this->assertStringNotContainsString('Installed ', $out);
        $this->assertFileDoesNotExist($home . '/Library/LaunchAgents/com.runnerdeck.plist');
    }

    public function testDarwinPrintEscapesTheCheckoutPathAndDoesNotInstall(): void
    {
        $home = sys_get_temp_dir() . '/runnerdeck-svc-' . bin2hex(random_bytes(4));
        mkdir($home);
        $script = $this->repo() . '/bin/install-service.sh';
        $bash = 'source ' . escapeshellarg($script)
            . '; ROOT=' . escapeshellarg('/tmp/a&b <c>')
            . '; PORT=8090; HOME=' . escapeshellarg($home)
            . '; darwin_plist';
        $rendered = shell_exec('bash -c ' . escapeshellarg($bash));
        $this->assertIsString($rendered);
        $this->assertStringContainsString('/tmp/a&amp;b &lt;c&gt;/run.sh', $rendered);
        $this->assertStringContainsString('<string>com.runnerdeck</string>', $rendered);
        $this->assertStringContainsString('<key>RunAtLoad</key>', $rendered);
        $this->assertStringNotContainsString('/tmp/a&b', $rendered);

        [$code, $out, $err] = $this->launch([], [
            'RUNNERDECK_SERVICE_OS' => 'Darwin',
            'HOME' => $home,
        ]);
        $this->assertSame(0, $code, $err);
        $this->assertStringContainsString($this->repo() . '/run.sh', $out);
        $this->assertStringContainsString('starts at login', $out);
        $this->assertFileDoesNotExist($home . '/Library/LaunchAgents/com.runnerdeck.plist');
    }

    public function testABadPortAndAnUnknownSystemAreRefused(): void
    {
        [$code, , $err] = $this->launch(['--port', '0']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('port must be 1-65535', $err);

        [$code, , $err] = $this->launch([], ['RUNNERDECK_SERVICE_OS' => 'Windows']);
        $this->assertSame(2, $code);
        $this->assertStringContainsString('Windows', $err);
    }
}
