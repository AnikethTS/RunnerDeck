<?php

declare(strict_types=1);

namespace RunnerDeck;

final class ProcessControl
{
    // /proc doesn't exist on macOS; /dev/fd works on both (a symlink to
    // /proc/self/fd on Linux).
    private const CLOSE_FDS_PREFIX =
        'for fd in /dev/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n<&-" 2>/dev/null; done; ';

    private const TERM_WAIT_SECONDS = 20;

    /** @var (callable(int, int): bool)|null */
    private static $killFake = null;

    /** @var (callable(int): bool)|null */
    private static $aliveFake = null;

    private static ?int $termWaitFake = null;

    /**
     * Test seam: intercept signals. Pass null to restore posix_kill.
     *
     * @param (callable(int, int): bool)|null $handler
     */
    public static function fakeKill(?callable $handler): void
    {
        self::$killFake = $handler;
    }

    /** @param (callable(int): bool)|null $handler */
    public static function fakeAlive(?callable $handler): void
    {
        self::$aliveFake = $handler;
    }

    /** Test seam: seconds to wait after SIGTERM. Pass 0 to skip sleeps. */
    public static function fakeTermWait(?int $seconds): void
    {
        self::$termWaitFake = $seconds;
    }

    public static function startIndividual(RunnerInfo $r, ?string $desiredName = null): array
    {
        if (!$r->configured) {
            $configure = Provisioner::ensureConfigured($r->dir, $desiredName ?? $r->agentName);
            if (!$configure['ok']) {
                return $configure;
            }
        }

        $resolved = ProcessDecision::resolveStart(
            RunnerPool::checkProcess("{$r->dir}/runner.pid"),
            RunnerPool::liveListenersByDir(),
            $r->dir
        );
        if ($resolved['running']) {
            if ($resolved['rewrite_pidfile']) {
                file_put_contents("{$r->dir}/runner.pid", (string) $resolved['pid']);
            }
            return ['ok' => true, 'message' => "{$r->id} already running (pid {$resolved['pid']})"];
        }

        RunnerLog::rotateIfOversized($r->dir);
        $result = Shell::exec(
            ['bash', '-c', self::CLOSE_FDS_PREFIX . 'nohup ./run.sh > runner.log 2>&1 & echo $!'],
            10,
            $r->dir
        );
        $newPid = ProcessDecision::parseSpawnedPid($result['stdout']);
        if ($newPid <= 0) {
            $message = "failed to start {$r->id}: " . trim($result['stderr']);
            return self::fail('process.start', $message, $r->id, $result['stderr']);
        }

        file_put_contents("{$r->dir}/runner.pid", (string) $newPid);
        AppLog::info('process.start', "{$r->id} started (pid {$newPid})", ['runner' => $r->id]);
        return ['ok' => true, 'message' => "{$r->id} started (pid {$newPid})"];
    }

    public static function stopIndividual(RunnerInfo $r): array
    {
        $pidFile = "{$r->dir}/runner.pid";
        $resolved = ProcessDecision::resolveStop(
            RunnerPool::checkProcess($pidFile),
            RunnerPool::liveListenersByDir(),
            $r->dir
        );

        if (!$resolved['running']) {
            return ['ok' => true, 'message' => "{$r->id} not running"];
        }

        $pid = (int) $resolved['pid'];
        if (!self::terminate($pid)) {
            return self::fail(
                'process.stop',
                "failed to stop {$r->id}: process {$pid} still running after SIGKILL",
                $r->id
            );
        }
        @unlink($pidFile);
        RunnerLog::rotateIfOversized($r->dir);
        AppLog::info('process.stop', "{$r->id} stopped (pid {$pid})", ['runner' => $r->id]);
        return ['ok' => true, 'message' => "{$r->id} stopped (pid {$pid})"];
    }

    /**
     * @return array{ok: false, message: string}
     */
    private static function fail(string $action, string $message, ?string $runner = null, string $stderr = ''): array
    {
        AppLog::error($action, $message, ['runner' => $runner, 'stderr' => $stderr]);
        return ['ok' => false, 'message' => $message];
    }

    private static function terminate(int $pid): bool
    {
        self::signal($pid, SIGTERM);
        $wait = self::$termWaitFake ?? self::TERM_WAIT_SECONDS;
        $deadline = time() + max(0, $wait);
        while (self::isAlive($pid) && time() < $deadline) {
            self::pause(100000);
        }
        if (self::isAlive($pid)) {
            self::signal($pid, SIGKILL);
            self::pause(200000);
        }
        return !self::isAlive($pid);
    }

    private static function signal(int $pid, int $signal): void
    {
        if (self::$killFake !== null) {
            (self::$killFake)($pid, $signal);
            return;
        }
        posix_kill($pid, $signal);
    }

    private static function isAlive(int $pid): bool
    {
        if (self::$aliveFake !== null) {
            return (self::$aliveFake)($pid);
        }
        if (self::$killFake !== null) {
            return false;
        }
        return posix_kill($pid, 0);
    }

    private static function pause(int $microseconds): void
    {
        if ((self::$termWaitFake ?? 1) === 0) {
            return;
        }
        usleep($microseconds);
    }

    public static function clearWorkDir(RunnerInfo $r): array
    {
        $resolved = ProcessDecision::resolveStop(
            RunnerPool::checkProcess("{$r->dir}/runner.pid"),
            RunnerPool::liveListenersByDir(),
            $r->dir
        );
        if ($resolved['running']) {
            return ['ok' => false, 'message' => "{$r->id} is running — stop it before clearing the work dir"];
        }
        $cleared = SlotDisk::clearWork($r->dir);
        if (!$cleared['ok']) {
            return self::fail('process.clear_work', $cleared['message'], $r->id);
        }
        AppLog::info('process.clear_work', "{$r->id} " . $cleared['message'], ['runner' => $r->id]);
        return ['ok' => true, 'message' => "{$r->id} " . $cleared['message']];
    }

    public static function restartIndividual(RunnerInfo $r): array
    {
        $stop = self::stopIndividual($r);
        if (!$stop['ok']) {
            return $stop;
        }
        usleep(500000);
        $fresh = new RunnerInfo(
            id: $r->id,
            dir: $r->dir,
            configured: $r->configured,
            agentName: $r->agentName,
            localRunning: false,
            pid: null,
            logTail: [],
        );
        return self::startIndividual($fresh);
    }

    /** Allocates the next free slot and installs/configures/starts it under an optional custom name. */
    public static function addRunner(?string $name): array
    {
        $id = RunnerPool::nextAvailableId();
        $info = new RunnerInfo(
            id: $id,
            dir: RunnerPool::dirFor($id),
            configured: false,
            agentName: RunnerPool::agentNameFor($id),
            localRunning: false,
            pid: null,
            logTail: [],
        );
        $result = self::startIndividual($info, $name);
        $result['id'] = $id;
        return $result;
    }

    /**
     * Stops the runner, deregisters it from GitHub if it was already
     * configured (so the old name is freed up), then reconfigures and
     * starts it fresh under the new name. Interrupts any in-progress job.
     */
    public static function renameRunner(RunnerInfo $r, string $newName): array
    {
        $deregister = self::stopAndDeregister($r);
        if (!$deregister['ok']) {
            return $deregister;
        }

        $fresh = new RunnerInfo(
            id: $r->id,
            dir: $r->dir,
            configured: false,
            agentName: $r->agentName,
            localRunning: false,
            pid: null,
            logTail: [],
        );
        $result = self::startIndividual($fresh, $newName);
        if ($result['ok']) {
            AppLog::info('process.rename', "{$r->id} renamed to {$newName}", ['runner' => $r->id]);
        }
        return $result;
    }

    /**
     * Stops the runner, deregisters it from GitHub, and permanently deletes
     * its local directory (binaries, config, logs — everything). There is
     * no undo. Interrupts any in-progress job.
     */
    public static function deleteRunner(RunnerInfo $r): array
    {
        $deregister = self::stopAndDeregister($r);
        if (!$deregister['ok']) {
            return $deregister;
        }

        $removed = self::removeDirectory($r->dir);
        if (!$removed['ok']) {
            return self::fail('process.delete', $removed['message'], $r->id, $removed['stderr']);
        }

        AppLog::info('process.delete', "{$r->id} deleted", ['runner' => $r->id]);
        return ['ok' => true, 'message' => "{$r->id} deleted"];
    }

    /**
     * Rename the slot out of the pool first so discover() cannot pick it up
     * mid-rm, then delete. A timed-out in-place rm left half-extracted dirs
     * that looked like unconfigured runners.
     *
     * @return array{ok: bool, message: string, stderr: string}
     */
    public static function removeDirectory(string $dir): array
    {
        if (!is_dir($dir)) {
            return ['ok' => true, 'message' => '', 'stderr' => ''];
        }

        $trash = rtrim($dir, '/') . '.deleting';
        if (is_dir($trash)) {
            Shell::exec(['rm', '-rf', $trash], 60);
        }
        $target = $dir;
        if (@rename($dir, $trash)) {
            $target = $trash;
        }

        $result = Shell::exec(['rm', '-rf', $target], 60);
        if ($result['code'] !== 0) {
            $detail = trim($result['stderr']);
            $message = "failed to delete files at {$target}"
                . ($detail !== '' ? ': ' . $detail : '');
            if ($target !== $dir) {
                $message .= ' — the slot is gone from the dashboard; remove that leftover directory by hand or retry';
            }
            return ['ok' => false, 'message' => $message, 'stderr' => $result['stderr']];
        }

        return ['ok' => true, 'message' => '', 'stderr' => ''];
    }

    /** Stops the runner and, if it was configured, deregisters it from GitHub and clears local registration files. */
    private static function stopAndDeregister(RunnerInfo $r): array
    {
        $stop = self::stopIndividual($r);
        if (!$stop['ok']) {
            return $stop;
        }

        if ($r->configured) {
            try {
                $ghId = GithubClient::listRunners()[$r->agentName]['id'] ?? null;
                if ($ghId !== null) {
                    GithubClient::deleteRunner($ghId);
                }
            } catch (\RuntimeException $e) {
                $message = "failed to deregister {$r->agentName}: " . $e->getMessage();
                return self::fail('process.deregister', $message, $r->id, $e->getMessage());
            }
            Provisioner::deregister($r->dir);
        }

        usleep(300000);

        return ['ok' => true, 'message' => "{$r->agentName} deregistered"];
    }

    private static function infoFor(string $id): RunnerInfo
    {
        $dir = RunnerPool::dirFor($id);
        return new RunnerInfo(
            id: $id,
            dir: $dir,
            configured: is_file("$dir/.runner"),
            agentName: RunnerPool::agentNameFor($id),
            localRunning: false,
            pid: null,
            logTail: [],
        );
    }

    public static function startAll(int $count = 10): array
    {
        $messages = [self::startIndividual(self::infoFor('runner-base'))['message']];

        for ($i = 1; $i <= $count; $i++) {
            $messages[] = self::startIndividual(self::infoFor("runner-$i"))['message'];
        }

        return ['ok' => true, 'message' => implode("\n", $messages)];
    }

    public static function stopAll(): array
    {
        $messages = [];
        foreach (RunnerPool::discover(0) as $info) {
            $messages[] = self::stopIndividual($info)['message'];
        }
        return ['ok' => true, 'message' => implode("\n", $messages)];
    }

    public static function resizePool(int $count): array
    {
        return self::startAll($count);
    }

    /** @param RunnerInfo[] $runners */
    public static function bulkStart(array $runners): array
    {
        $messages = [];
        foreach ($runners as $r) {
            $messages[] = self::startIndividual($r)['message'];
        }
        return ['ok' => true, 'message' => implode("\n", $messages)];
    }

    /** @param RunnerInfo[] $runners */
    public static function bulkStop(array $runners): array
    {
        $messages = [];
        foreach ($runners as $r) {
            $messages[] = self::stopIndividual($r)['message'];
        }
        return ['ok' => true, 'message' => implode("\n", $messages)];
    }

    /** @param RunnerInfo[] $runners */
    public static function bulkDelete(array $runners): array
    {
        $messages = [];
        $ok = true;
        foreach ($runners as $r) {
            $result = self::deleteRunner($r);
            $messages[] = $result['message'];
            $ok = $ok && $result['ok'];
        }
        return ['ok' => $ok, 'message' => implode("\n", $messages)];
    }
}
