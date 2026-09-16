<?php

declare(strict_types=1);

final class StartAllAction
{
    public static function handle(): never
    {
        $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
        Api::respond(ProcessControl::startAll($count));
    }
}

final class StopAllAction
{
    public static function handle(): never
    {
        $allRunners = RunnerPool::discover(0);
        if ($blocked = Api::checkNoneBusy($allRunners, Api::force())) {
            Api::respond($blocked, 409);
        }
        Api::markExplicitlyStopped($allRunners);
        Api::respond(ProcessControl::stopAll());
    }
}

final class BulkStartAction
{
    public static function handle(): never
    {
        Api::respond(ProcessControl::bulkStart(Api::requireRunners()));
    }
}

final class BulkStopAction
{
    public static function handle(): never
    {
        $runners = Api::requireRunners();
        if ($blocked = Api::checkNoneBusy($runners, Api::force())) {
            Api::respond($blocked, 409);
        }
        Api::markExplicitlyStopped($runners);
        Api::respond(ProcessControl::bulkStop($runners));
    }
}

final class BulkDeleteAction
{
    public static function handle(): never
    {
        $runners = Api::requireRunners();
        if ($blocked = Api::checkNoneBusy($runners, Api::force())) {
            Api::respond($blocked, 409);
        }
        Api::markExplicitlyStopped($runners);
        Api::respond(ProcessControl::bulkDelete($runners));
    }
}

final class ResizeAction
{
    public static function handle(): never
    {
        $count = max(1, min(30, (int) ($_POST['count'] ?? 10)));
        Api::respond(ProcessControl::resizePool($count));
    }
}
