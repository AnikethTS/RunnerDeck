<?php

declare(strict_types=1);

final class StartAction
{
    public static function handle(): never
    {
        $r = Api::requireRunner($_POST['runner'] ?? '');
        Api::respond(ProcessControl::startIndividual($r));
    }
}

final class StopAction
{
    public static function handle(): never
    {
        $r = Api::requireRunner($_POST['runner'] ?? '');
        if ($blocked = Api::checkNotBusy($r->agentName, Api::force())) {
            Api::respond($blocked, 409);
        }
        CrashState::markExplicitlyStopped($r->id);
        Api::respond(ProcessControl::stopIndividual($r));
    }
}

final class RestartAction
{
    public static function handle(): never
    {
        $r = Api::requireRunner($_POST['runner'] ?? '');
        if ($blocked = Api::checkNotBusy($r->agentName, Api::force())) {
            Api::respond($blocked, 409);
        }
        CrashState::markExplicitlyStopped($r->id);
        Api::respond(ProcessControl::restartIndividual($r));
    }
}

final class RenameAction
{
    public static function handle(): never
    {
        $r = Api::requireRunner($_POST['runner'] ?? '');
        $newName = trim((string) ($_POST['name'] ?? ''));
        if ($newName === '') {
            Api::respond(['ok' => false, 'message' => 'name is required'], 422);
        }
        if ($blocked = Api::checkNotBusy($r->agentName, Api::force())) {
            Api::respond($blocked, 409);
        }
        Api::respond(ProcessControl::renameRunner($r, $newName));
    }
}

final class AddRunnerAction
{
    public static function handle(): never
    {
        $name = trim((string) ($_POST['name'] ?? ''));
        Api::respond(ProcessControl::addRunner($name !== '' ? $name : null));
    }
}

final class DeleteRunnerAction
{
    public static function handle(): never
    {
        $r = Api::requireRunner($_POST['runner'] ?? '');
        if ($blocked = Api::checkNotBusy($r->agentName, Api::force())) {
            Api::respond($blocked, 409);
        }
        CrashState::markExplicitlyStopped($r->id);
        Api::respond(ProcessControl::deleteRunner($r));
    }
}
