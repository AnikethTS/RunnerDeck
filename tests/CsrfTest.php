<?php

declare(strict_types=1);

namespace Tests;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class CsrfTest extends TestCase
{
    #[RunInSeparateProcess]
    public function testTokenIsStableWithinASession(): void
    {
        $first = \RunnerDeck\Core\Csrf::token();
        $second = \RunnerDeck\Core\Csrf::token();

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestRejectsMissingToken(): void
    {
        \RunnerDeck\Core\Csrf::token();
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $this->assertFalse(\RunnerDeck\Core\Csrf::verifyRequest());
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestRejectsWrongToken(): void
    {
        \RunnerDeck\Core\Csrf::token();
        $_POST['csrf_token'] = 'not-the-right-token';

        $this->assertFalse(\RunnerDeck\Core\Csrf::verifyRequest());
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestAcceptsMatchingPostField(): void
    {
        $token = \RunnerDeck\Core\Csrf::token();
        $_POST['csrf_token'] = $token;

        $this->assertTrue(\RunnerDeck\Core\Csrf::verifyRequest());
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestAcceptsMatchingHeader(): void
    {
        $token = \RunnerDeck\Core\Csrf::token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $this->assertTrue(\RunnerDeck\Core\Csrf::verifyRequest());
    }
}
