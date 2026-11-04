<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Event\Test\Prepared;
use PHPUnit\Event\Test\PreparedSubscriber;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class CiProgressExtension implements Extension
{
    public function bootstrap(Configuration $configuration, Facade $facade, ParameterCollection $parameters): void
    {
        if (getenv('CI') !== 'true' && getenv('GITHUB_ACTIONS') !== 'true') {
            return;
        }
        $facade->registerSubscribers(
            new CiProgressStarted(),
            new CiProgressPrepared(),
        );
    }
}

final class CiProgressStarted implements ExecutionStartedSubscriber
{
    public function notify(ExecutionStarted $event): void
    {
        CiProgress::$total = $event->testSuite()->count();
        CiProgress::$current = 0;
    }
}

final class CiProgressPrepared implements PreparedSubscriber
{
    public function notify(Prepared $event): void
    {
        CiProgress::$current++;
        fwrite(
            STDOUT,
            sprintf("[%d/%d] %s\n", CiProgress::$current, CiProgress::$total, $event->test()->id())
        );
        fflush(STDOUT);
    }
}

final class CiProgress
{
    public static int $total = 0;

    public static int $current = 0;
}
