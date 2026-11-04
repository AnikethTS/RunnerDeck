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
        $first = \RunnerDeck\Security\Csrf::token();
        $second = \RunnerDeck\Security\Csrf::token();

        $this->assertSame($first, $second);
        $this->assertSame(64, strlen($first));
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestRejectsMissingToken(): void
    {
        \RunnerDeck\Security\Csrf::token();
        $_POST = [];
        $_SERVER['HTTP_X_CSRF_TOKEN'] = '';

        $this->assertFalse(\RunnerDeck\Security\Csrf::verifyRequest());
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestRejectsWrongToken(): void
    {
        \RunnerDeck\Security\Csrf::token();
        $_POST['csrf_token'] = 'not-the-right-token';

        $this->assertFalse(\RunnerDeck\Security\Csrf::verifyRequest());
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestAcceptsMatchingPostField(): void
    {
        $token = \RunnerDeck\Security\Csrf::token();
        $_POST['csrf_token'] = $token;

        $this->assertTrue(\RunnerDeck\Security\Csrf::verifyRequest());
    }

    #[RunInSeparateProcess]
    public function testVerifyRequestAcceptsMatchingHeader(): void
    {
        $token = \RunnerDeck\Security\Csrf::token();
        $_SERVER['HTTP_X_CSRF_TOKEN'] = $token;

        $this->assertTrue(\RunnerDeck\Security\Csrf::verifyRequest());
    }
}
