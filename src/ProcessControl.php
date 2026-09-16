<?php

declare(strict_types=1);

final class ProcessControl
{
    // /proc doesn't exist on macOS; /dev/fd works on both (a symlink to
    // /proc/self/fd on Linux).
    private const CLOSE_FDS_PREFIX =
        'for fd in /dev/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n<&-" 2>/dev/null; done; ';

    /** @var (callable(int): bool)|null */
    private static $killFake = null;

    /**
     * Test seam: intercept SIGTERM. Pass null to restore posix_kill.
     *
     * @param (callable(int): bool)|null $handler
     */
    public static function fakeKill(?callable $handler): void
    {
        self::$killFake = $handler;
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
        self::terminate($pid);
        @unlink($pidFile);
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

    private static function terminate(int $pid): void
    {
        if (self::$killFake !== null) {
            (self::$killFake)($pid);
            return;
        }
        posix_kill($pid, SIGTERM);
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
        return self::startIndividual($fresh, $newName);
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

        $result = Shell::exec(['rm', '-rf', $r->dir], 15);
        if ($result['code'] !== 0) {
            $message = "failed to delete {$r->id}'s files: " . trim($result['stderr']);
            return self::fail('process.delete', $message, $r->id, $result['stderr']);
        }

        return ['ok' => true, 'message' => "{$r->id} deleted"];
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
            } catch (RuntimeException $e) {
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
