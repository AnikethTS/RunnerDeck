<?php

final class ProcessControl
{
    private const CLOSE_FDS_PREFIX =
        'for fd in /proc/self/fd/*; do n=${fd##*/}; [ "$n" -gt 2 ] 2>/dev/null && eval "exec $n<&-" 2>/dev/null; done; ';

    public static function startIndividual(RunnerInfo $r): array
    {
        if (!$r->configured) {
            $configure = Provisioner::ensureConfigured($r->dir, $r->agentName);
            if (!$configure['ok']) {
                return $configure;
            }
        }

        [$running, $pid] = RunnerPool::checkProcess("{$r->dir}/runner.pid");
        if ($running) {
            return ['ok' => true, 'message' => "{$r->id} already running (pid {$pid})"];
        }

        $existingPid = RunnerPool::liveListenersByDir()[$r->dir] ?? null;
        if ($existingPid !== null) {
            file_put_contents("{$r->dir}/runner.pid", (string) $existingPid);
            return ['ok' => true, 'message' => "{$r->id} already running (pid {$existingPid})"];
        }

        $result = Shell::exec(
            ['bash', '-c', self::CLOSE_FDS_PREFIX . 'nohup ./run.sh > runner.log 2>&1 & echo $!'],
            10,
            $r->dir
        );
        $newPid = (int) trim($result['stdout']);
        if ($newPid <= 0) {
            return ['ok' => false, 'message' => "failed to start {$r->id}: " . trim($result['stderr'])];
        }

        file_put_contents("{$r->dir}/runner.pid", (string) $newPid);
        return ['ok' => true, 'message' => "{$r->id} started (pid {$newPid})"];
    }

    public static function stopIndividual(RunnerInfo $r): array
    {
        $pidFile = "{$r->dir}/runner.pid";
        [$running, $pid] = RunnerPool::checkProcess($pidFile);

        if (!$running) {
            $found = RunnerPool::liveListenersByDir()[$r->dir] ?? null;
            if ($found !== null) {
                $running = true;
                $pid = $found;
            }
        }

        if (!$running) {
            return ['ok' => true, 'message' => "{$r->id} not running"];
        }

        posix_kill($pid, SIGTERM);
        @unlink($pidFile);
        return ['ok' => true, 'message' => "{$r->id} stopped (pid {$pid})"];
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
}
