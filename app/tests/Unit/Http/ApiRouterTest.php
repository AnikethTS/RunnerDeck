<?php

declare(strict_types=1);

namespace Tests\Unit\Http;

use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use RunnerDeck\Http\Action\Authentication as Auth;
use RunnerDeck\Http\Api;
use RunnerDeck\Security\Csrf;
use RunnerDeck\Security\Totp;
use Tests\Support\HttpActionHarness;
use Tests\Support\HttpResponse;

/** Each test runs in its own process: the router starts a PHP session. */
final class ApiRouterTest extends TestCase
{
    use HttpActionHarness;

    protected function setUp(): void
    {
        $this->setUpHttp();
        $_SERVER['REMOTE_ADDR'] = '127.0.0.1';
    }

    protected function tearDown(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET');
        unset($_SERVER['HTTP_X_CSRF_TOKEN'], $_SERVER['REQUEST_METHOD']);
        $this->tearDownHttp();
    }

    /**
     * Send a request through Api::run().
     *
     * @param array<string, mixed> $post
     * @return array{status: int, data: array<string, mixed>}
     */
    private function request(string $method, string $action, array $post = [], array $get = []): array
    {
        $_SERVER['REQUEST_METHOD'] = $method;
        $_GET = ['action' => $action] + $get;
        $_POST = $post;
        Api::fakeRespond(static function (array $data, int $status): never {
            throw new HttpResponse((string) json_encode(['status' => $status, 'data' => $data]));
        });
        ob_start();
        try {
            Api::run();
        } catch (HttpResponse $r) {
            /** @var array{status: int, data: array<string, mixed>} $payload */
            $payload = json_decode($r->getMessage(), true);
            return $payload;
        } finally {
            ob_end_clean();
        }
    }

    #[RunInSeparateProcess]
    public function testCsrfTokenIsServedToAGet(): void
    {
        $res = $this->request('GET', 'csrf_token');

        $this->assertSame(200, $res['status']);
        $this->assertSame(64, strlen($res['data']['token']));
    }

    #[RunInSeparateProcess]
    public function testReadActionsAreRoutedByGet(): void
    {
        $res = $this->request('GET', 'history');

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
    }

    #[RunInSeparateProcess]
    public function testAnUnknownGetActionIsNotFound(): void
    {
        $res = $this->request('GET', 'nope');

        $this->assertSame(404, $res['status']);
        $this->assertSame('not found', $res['data']['message']);
    }

    #[RunInSeparateProcess]
    public function testOtherMethodsAreNotFound(): void
    {
        foreach (['PUT', 'DELETE', 'PATCH'] as $method) {
            $res = $this->request($method, 'history');

            $this->assertSame(404, $res['status'], $method);
        }
    }

    #[RunInSeparateProcess]
    public function testAPostWithoutACsrfTokenIsRefused(): void
    {
        $this->slot('runner-1');

        $res = $this->request('POST', 'stop', ['runner' => 'runner-1', 'force' => '1']);

        $this->assertSame(403, $res['status']);
        $this->assertStringContainsString('CSRF token', $res['data']['message']);
    }

    #[RunInSeparateProcess]
    public function testAPostWithTheSessionTokenIsRouted(): void
    {
        $this->slot('runner-1');
        $token = Csrf::token();

        $res = $this->request('POST', 'stop', ['runner' => 'runner-1', 'force' => '1', 'csrf_token' => $token]);

        $this->assertSame(200, $res['status']);
        $this->assertSame('runner-1 not running', $res['data']['message']);
    }

    #[RunInSeparateProcess]
    public function testTheTokenMayComeInAHeaderToo(): void
    {
        $_SERVER['HTTP_X_CSRF_TOKEN'] = Csrf::token();

        $res = $this->request('POST', 'clear_applog');

        $this->assertSame(200, $res['status']);
        $this->assertSame('Log cleared', $res['data']['message']);
    }

    #[RunInSeparateProcess]
    public function testAnUnknownPostActionIsNotFound(): void
    {
        $res = $this->request('POST', 'self_destruct', ['csrf_token' => Csrf::token()]);

        $this->assertSame(404, $res['status']);
        $this->assertSame('unknown action', $res['data']['message']);
    }

    #[RunInSeparateProcess]
    public function testSessionWritingActionsKeepTheSessionOpen(): void
    {
        $res = $this->request('POST', 'totp_begin', ['csrf_token' => Csrf::token()]);

        $this->assertSame(200, $res['status']);
        $this->assertStringStartsWith('otpauth://totp/RunnerDeck?secret=', $res['data']['uri']);
        $this->assertNotSame('', $res['data']['secret']);
    }

    #[RunInSeparateProcess]
    public function testTotpConfirmRejectsAWrongCode(): void
    {
        $token = Csrf::token();
        $this->request('POST', 'totp_begin', ['csrf_token' => $token]);

        $res = $this->request('POST', 'totp_confirm', ['csrf_token' => $token, 'code' => '000000']);

        $this->assertSame(422, $res['status']);
        $this->assertSame('Invalid code', $res['data']['message']);
    }

    #[RunInSeparateProcess]
    public function testTotpConfirmAcceptsTheRightCodeAndIssuesRecoveryCodes(): void
    {
        $token = Csrf::token();
        $begin = $this->request('POST', 'totp_begin', ['csrf_token' => $token]);

        $res = $this->request('POST', 'totp_confirm', [
            'csrf_token' => $token,
            'code' => Totp::code($begin['data']['secret']),
        ]);

        $this->assertSame(200, $res['status']);
        $this->assertTrue($res['data']['ok']);
        $this->assertNotEmpty($res['data']['recovery_codes']);
    }

    #[RunInSeparateProcess]
    public function testLoginNeedsAValidCsrfToken(): void
    {
        $res = $this->request('POST', 'login', ['code' => '123456']);

        $this->assertSame(403, $res['status']);
    }

    #[RunInSeparateProcess]
    public function testLoginIsUnavailableWhenSignInIsOff(): void
    {
        $res = $this->request('POST', 'login', ['csrf_token' => Csrf::token(), 'code' => '123456']);

        $this->assertSame(400, $res['status']);
        $this->assertSame('login is not enabled', $res['data']['message']);
    }

    #[RunInSeparateProcess]
    public function testLoginWithTheRightCodeSucceedsAndAWrongOneIsRejected(): void
    {
        $secret = Totp::generateSecret();
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . $secret);
        $token = Csrf::token();

        $wrong = $this->request('POST', 'login', ['csrf_token' => $token, 'code' => '000000']);
        $this->assertSame(401, $wrong['status']);
        $this->assertSame('invalid code', $wrong['data']['message']);

        $right = $this->request('POST', 'login', ['csrf_token' => $token, 'code' => Totp::code($secret)]);
        $this->assertSame(200, $right['status']);
        $this->assertTrue($right['data']['ok']);
    }

    #[RunInSeparateProcess]
    public function testRepeatedWrongCodesLockLoginOut(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . Totp::generateSecret());
        $token = Csrf::token();

        $status = 0;
        $last = [];
        for ($i = 0; $i < 12 && $status !== 429; $i++) {
            $last = $this->request('POST', 'login', ['csrf_token' => $token, 'code' => '000000']);
            $status = $last['status'];
        }

        $this->assertSame(429, $status);
        $this->assertSame('too many attempts', $last['data']['message']);
        $this->assertArrayHasKey('retryAfter', $last['data']);
    }

    #[RunInSeparateProcess]
    public function testRecoveryCodeLoginIsTried(): void
    {
        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . Totp::generateSecret());

        $res = $this->request('POST', 'login', [
            'csrf_token' => Csrf::token(), 'recovery_code' => 'AAAA-BBBB-CCCC',
        ]);

        $this->assertSame(401, $res['status']);
    }

    #[RunInSeparateProcess]
    public function testLogoutAlwaysSucceeds(): void
    {
        $off = $this->request('POST', 'logout', ['csrf_token' => Csrf::token()]);
        $this->assertSame(200, $off['status']);

        putenv('RUNNERDECK_AUTH_TOTP_SECRET=' . Totp::generateSecret());
        $on = $this->call(Auth\LogoutAction::class);
        $this->assertSame(200, $on['status']);
        $this->assertTrue($on['data']['ok']);
    }

    #[RunInSeparateProcess]
    public function testActionsDirectlyReturnTheirJson(): void
    {
        $token = $this->call(Auth\CsrfTokenAction::class);

        $this->assertSame(200, $token['status']);
        $this->assertTrue($token['data']['ok']);
    }

    #[RunInSeparateProcess]
    public function testRespondSendsJsonWithTheStatusCode(): void
    {
        ob_start();
        try {
            Api::fakeRespond(static function (array $data, int $status): never {
                throw new HttpResponse((string) $status);
            });
            Api::respond(['ok' => false, 'message' => 'x'], 418);
        } catch (HttpResponse $r) {
            $this->assertSame('418', $r->getMessage());
            $this->assertSame('{"ok":false,"message":"x"}', ob_get_contents());
        } finally {
            ob_end_clean();
        }
        $this->assertSame(418, http_response_code());
    }
}
