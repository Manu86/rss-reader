<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Database\ConnectionFactory;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Security\NativeSession;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;
use Tests\Support\TestApplication;

final class SessionCloseTest extends TestCase
{
    private PDO $pdo;

    private TestApplication $application;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        $this->application = new TestApplication($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $this->application->userService->create('alice', 'correct horse battery staple');
    }

    public function testReadOnlyGetReleasesTheSessionWriteLock(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->csrfToken($kernel);
        $this->loginOn($kernel, $csrf);

        $response = $kernel->handle($this->request('GET', '/api/auth/me'));

        self::assertSame(200, $response->status);
        self::assertTrue($session->closed);
    }

    public function testMutatingRequestKeepsTheSessionLock(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->csrfToken($kernel);
        $csrf = $this->loginOn($kernel, $csrf);

        $response = $kernel->handle($this->request('POST', '/api/auth/logout', [
            'x-csrf-token' => $csrf,
        ]));

        self::assertSame(204, $response->status);
        self::assertFalse($session->closed);
    }

    public function testCsrfBootstrapKeepsTheSessionLock(): void
    {
        $kernel = $this->application->kernel(new ArraySession());

        $kernel->handle($this->request('GET', '/api/auth/csrf'));

        self::assertFalse($this->sessionOf($kernel)->closed);
    }

    public function testLateWriterAfterCloseStillPersistsSecurityState(): void
    {
        $session = new ArraySession();
        $session->close();
        $session->set('csrf_token', str_repeat('a', 64));

        self::assertSame(str_repeat('a', 64), $session->get('csrf_token'));
        self::assertFalse($session->closed);
    }

    public function testNativeSessionCloseReleasesAndSetReopens(): void
    {
        $session = new NativeSession('native_session_test', 3600, false);
        $session->set('key', 'value');
        $session->close();
        // Une écriture après close() réouvre le stockage au lieu d'échouer
        // silencieusement : aucun état de sécurité ne peut être perdu.
        $session->set('other', 'value2');
        self::assertSame('value2', $session->get('other'));
        $session->close();
        @session_destroy();
        $_SESSION = [];
    }

    public function testInvalidationAfterCloseStillReturns401(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->csrfToken($kernel);
        $this->loginOn($kernel, $csrf);
        // Le verrou est relâché, puis le compte est désactivé : le prochain
        // GET /me doit invalider la session et renvoyer 401, pas 500.
        $kernel->handle($this->request('GET', '/api/auth/me'));
        self::assertTrue($session->closed);
        $this->application->userService->setActive('alice', false);

        $response = $kernel->handle($this->request('GET', '/api/auth/me'));

        self::assertSame(401, $response->status);
    }

    private function sessionOf(ApiKernel $kernel): ArraySession
    {
        $property = new \ReflectionProperty($kernel, 'currentUser');
        $property->setAccessible(true);
        $currentUser = $property->getValue($kernel);
        $sessionProperty = new \ReflectionProperty($currentUser, 'session');
        $sessionProperty->setAccessible(true);

        return $sessionProperty->getValue($currentUser);
    }

    private function loginOn(ApiKernel $kernel, string $csrf): string
    {
        $response = $kernel->handle($this->request('POST', '/api/auth/login', [
            'content-type' => 'application/json',
            'x-csrf-token' => $csrf,
        ], json_encode([
            'username' => 'alice',
            'password' => 'correct horse battery staple',
        ], JSON_THROW_ON_ERROR), '192.0.2.1'));
        self::assertSame(200, $response->status, (string) $response->body);
        $body = json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);

        return (string) $body['data']['csrf_token'];
    }

    private function csrfToken(ApiKernel $kernel): string
    {
        $response = $kernel->handle($this->request('GET', '/api/auth/csrf'));
        $body = json_decode((string) $response->body, true, 512, JSON_THROW_ON_ERROR);
        $token = $body['data']['csrf_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** @param array<string, string> $headers */
    private function request(string $method, string $path, array $headers = [], string $body = '', string $remoteAddress = ''): Request
    {
        return new Request($method, $path, $headers, $body, $remoteAddress, [], [], []);
    }
}
