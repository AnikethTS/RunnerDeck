<?php

declare(strict_types=1);

require __DIR__ . '/../../src/bootstrap.php';

use RunnerDeck\Core\Platform;
use RunnerDeck\Pool\RunnerPool;
use RunnerDeck\Pool\WindowsProcess;

function check(bool $ok, string $label): void
{
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) {
        exit(1);
    }
}

check(Platform::isWindows(), 'running on Windows');

$pool = $argv[1] ?? '';
check($pool !== '' && is_dir($pool), 'scratch dir given');
$pool = rtrim(str_replace('\\', '/', $pool), '/');
putenv('RUNNERDECK_POOL_DIR=' . $pool);

$dir = $pool . '/runner-1';
mkdir($dir . '/bin', 0755, true);
copy(getenv('SystemRoot') . '\\System32\\ping.exe', $dir . '/bin/Runner.Listener.exe');
file_put_contents($dir . '/run.cmd', "@echo off\r\nbin\\Runner.Listener.exe -t 127.0.0.1\r\n");

$spawn = WindowsProcess::spawnRunner($dir);
$spawned = (int) trim($spawn['stdout']);
check($spawn['code'] === 0 && $spawned > 0, 'spawnRunner returns a pid: ' . trim($spawn['stdout'] . $spawn['stderr']));

$pid = null;
for ($i = 0; $i < 20 && $pid === null; $i++) {
    RunnerPool::resetListenersCache();
    $pid = RunnerPool::liveListenersByDir()[$dir] ?? null;
    if ($pid === null) {
        usleep(500000);
    }
}
check($pid !== null, 'listener found under the pool dir spelling');
check(WindowsProcess::isListener($pid), 'isListener');
check(WindowsProcess::exists($pid), 'exists');

$stats = WindowsProcess::processStats();
check(isset($stats[$pid]) && $stats[$pid]['rss_kb'] > 0, 'processStats includes the listener');

$mem = WindowsProcess::memoryKb();
check($mem !== null && $mem[0] > 0 && $mem[1] > 0, 'memoryKb');

check(WindowsProcess::kill($spawned) || WindowsProcess::kill($pid), 'kill');
usleep(1000000);
check(!WindowsProcess::exists($pid), 'listener gone after kill');
RunnerPool::resetListenersCache();
check(!isset(RunnerPool::liveListenersByDir()[$dir]), 'no listener reported after kill');

echo "windows smoke passed\n";
