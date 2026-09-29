<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\ConnectionFactory;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;
use Tests\Support\TestApplication;

final class AuthenticationApiTest extends TestCase
{
    private PDO $pdo;
    private TestApplication $application;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        $this->application = new TestApplication($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $this->application->userService->create('alice', 'correct horse battery staple');
        $this->application->userService->create('bob', 'another correct horse battery');
    }

    public function testLoginRegeneratesSessionAndMeReturnsNoSensitiveData(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $login = $this->login($kernel, 'alice', 'correct horse battery staple');

        self::assertSame(200, $login->status);
        self::assertSame(1, $session->regenerationCount);
        $loginData = $this->decode($login);
        self::assertSame('alice', $loginData['data']['user']['username']);
        self::assertNull($loginData['data']['user']['email']);
        self::assertArrayNotHasKey('password_hash', $loginData['data']['user']);

        $me = $kernel->handle(new Request('GET', '/api/auth/me'));
        self::assertSame(200, $me->status);
        $meData = $this->decode($me);
        self::assertSame('alice', $meData['data']['username']);
        self::assertNull($meData['data']['email']);
        self::assertArrayNotHasKey('password_hash', $meData['data']);
        self::assertSame('no-store', $me->headers['Cache-Control']);
    }

    public function testTwoUsersHaveIndependentSessions(): void
    {
        $aliceSession = new ArraySession();
        $bobSession = new ArraySession();
        $aliceKernel = $this->application->kernel($aliceSession);
        $bobKernel = $this->application->kernel($bobSession);
        $this->login($aliceKernel, 'alice', 'correct horse battery staple');
        $this->login($bobKernel, 'bob', 'another correct horse battery');

        $alice = $this->decode($aliceKernel->handle(new Request('GET', '/api/auth/me')));
        $bob = $this->decode($bobKernel->handle(new Request('GET', '/api/auth/me')));

        self::assertSame('alice', $alice['data']['username']);
        self::assertSame('bob', $bob['data']['username']);
        self::assertNotSame($alice['data']['id'], $bob['data']['id']);
    }

    public function testUnknownUserAndWrongPasswordUseSameGenericError(): void
    {
        $knownKernel = $this->application->kernel(new ArraySession());
        $unknownKernel = $this->application->kernel(new ArraySession());

        $known = $this->login($knownKernel, 'alice', 'wrong password');
        $unknown = $this->login($unknownKernel, 'nobody', 'wrong password');

        self::assertSame(401, $known->status);
        self::assertSame($known->body, $unknown->body);
        self::assertStringNotContainsString('alice', $known->body);
    }

    public function testDisabledUserCannotLoginAndExistingSessionIsInvalidated(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $this->login($kernel, 'alice', 'correct horse battery staple');
        self::assertTrue($this->application->userService->setActive('alice', false));

        $me = $kernel->handle(new Request('GET', '/api/auth/me'));
        self::assertSame(401, $me->status);
        self::assertSame(2, $session->regenerationCount);

        $newKernel = $this->application->kernel(new ArraySession());
        self::assertSame(401, $this->login(
            $newKernel,
            'alice',
            'correct horse battery staple',
        )->status);
    }

    public function testEveryMutationRequiresValidCsrfToken(): void
    {
        $kernel = $this->application->kernel(new ArraySession());
        $response = $kernel->handle(new Request(
            'POST',
            '/api/auth/login',
            ['content-type' => 'application/json'],
            '{"username":"alice","password":"correct horse battery staple"}',
            '192.0.2.1',
        ));

        self::assertSame(403, $response->status);
        self::assertSame('INVALID_CSRF_TOKEN', $this->decode($response)['error']['code']);
    }

    public function testOversizedJsonRequestIsRejected(): void
    {
        $kernel = $this->application->kernel(new ArraySession());
        $csrfResponse = $kernel->handle(new Request('GET', '/api/auth/csrf'));
        $csrf = $this->decode($csrfResponse)['data']['csrf_token'];
        self::assertIsString($csrf);

        $response = $kernel->handle(new Request(
            'POST',
            '/api/auth/login',
            [
                'content-type' => 'application/json',
                'x-csrf-token' => $csrf,
            ],
            str_repeat('a', 65_537),
        ));

        self::assertSame(413, $response->status);
        self::assertSame('REQUEST_TOO_LARGE', $this->decode($response)['error']['code']);
    }

    public function testLogoutInvalidatesSession(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $login = $this->login($kernel, 'alice', 'correct horse battery staple');
        $csrf = $this->decode($login)['data']['csrf_token'];
        self::assertIsString($csrf);

        $logout = $kernel->handle(new Request(
            'POST',
            '/api/auth/logout',
            ['x-csrf-token' => $csrf],
        ));
        self::assertSame(204, $logout->status);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/auth/me'))->status);
    }

    public function testAuthenticatedUserCanChangePassword(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $login = $this->login($kernel, 'alice', 'correct horse battery staple');
        $csrf = $this->decode($login)['data']['csrf_token'];
        self::assertIsString($csrf);

        $change = $kernel->handle(new Request(
            'POST',
            '/api/settings/password',
            [
                'content-type' => 'application/json',
                'x-csrf-token' => $csrf,
            ],
            json_encode([
                'current_password' => 'correct horse battery staple',
                'new_password' => 'this is the replacement password',
            ], JSON_THROW_ON_ERROR),
        ));
        self::assertSame(200, $change->status);
        self::assertSame(2, $session->regenerationCount);

        $oldPasswordKernel = $this->application->kernel(new ArraySession());
        self::assertSame(401, $this->login(
            $oldPasswordKernel,
            'alice',
            'correct horse battery staple',
        )->status);
        $newPasswordKernel = $this->application->kernel(new ArraySession());
        self::assertSame(200, $this->login(
            $newPasswordKernel,
            'alice',
            'this is the replacement password',
        )->status);
    }

    public function testLoginRateLimitIsPersistentAcrossSessions(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $kernel = $this->application->kernel(new ArraySession());
            self::assertSame(401, $this->login($kernel, 'alice', 'wrong password')->status);
        }

        $kernel = $this->application->kernel(new ArraySession());
        $limited = $this->login($kernel, 'alice', 'correct horse battery staple');
        self::assertSame(429, $limited->status);
        self::assertSame('TOO_MANY_ATTEMPTS', $this->decode($limited)['error']['code']);
    }

    public function testLoginRateLimitFollowsTheAccountAcrossAddresses(): void
    {
        for ($attempt = 0; $attempt < 5; ++$attempt) {
            $kernel = $this->application->kernel(new ArraySession());
            self::assertSame(401, $this->login($kernel, 'alice', 'wrong password', '198.51.100.' . $attempt)->status);
        }

        $kernel = $this->application->kernel(new ArraySession());
        $limited = $this->login($kernel, 'alice', 'correct horse battery staple', '198.51.100.99');
        self::assertSame(429, $limited->status);
        self::assertSame('TOO_MANY_ATTEMPTS', $this->decode($limited)['error']['code']);
    }

    public function testLoginRateLimitFollowsTheAddressAcrossAccounts(): void
    {
        for ($attempt = 0; $attempt < 19; ++$attempt) {
            $kernel = $this->application->kernel(new ArraySession());
            $status = $this->login($kernel, 'absent-' . $attempt, 'wrong password')->status;
            self::assertContains($status, [401], 'Chaque compte reste sous sa propre limite');
        }

        $kernel = $this->application->kernel(new ArraySession());
        $allowed = $this->login($kernel, 'alice', 'correct horse battery staple');
        self::assertSame(200, $allowed->status, 'Une adresse sous sa limite peut se connecter');

        $kernel = $this->application->kernel(new ArraySession());
        $failure = $this->login($kernel, 'bob', 'wrong password');
        self::assertSame(401, $failure->status);

        $kernel = $this->application->kernel(new ArraySession());
        $limited = $this->login($kernel, 'alice', 'correct horse battery staple');
        self::assertSame(429, $limited->status);
    }

    public function testSuccessfulLoginClearsTheAccountAttemptsOnly(): void
    {
        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $kernel = $this->application->kernel(new ArraySession());
            self::assertSame(401, $this->login($kernel, 'alice', 'wrong password')->status);
        }

        $kernel = $this->application->kernel(new ArraySession());
        self::assertSame(200, $this->login($kernel, 'alice', 'correct horse battery staple')->status);

        for ($attempt = 0; $attempt < 4; ++$attempt) {
            $kernel = $this->application->kernel(new ArraySession());
            self::assertSame(401, $this->login($kernel, 'alice', 'wrong password')->status);
        }

        $kernel = $this->application->kernel(new ArraySession());
        self::assertSame(200, $this->login($kernel, 'alice', 'correct horse battery staple')->status);
    }

    private function login(
        ApiKernel $kernel,
        string $username,
        string $password,
        string $address = '192.0.2.1',
    ): Response {
        $csrfResponse = $kernel->handle(new Request('GET', '/api/auth/csrf'));
        $csrf = $this->decode($csrfResponse)['data']['csrf_token'];
        self::assertIsString($csrf);

        return $kernel->handle(new Request(
            'POST',
            '/api/auth/login',
            [
                'content-type' => 'application/json',
                'x-csrf-token' => $csrf,
            ],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR),
            $address,
        ));
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
