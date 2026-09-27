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

final class RememberMeApiTest extends TestCase
{
    private const COOKIE = 'rss_reader_remember';

    private PDO $pdo;
    private TestApplication $application;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        $this->application = new TestApplication($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $this->application->userService->create('alice', 'correct horse battery staple');
        $this->application->userService->create('bob', 'another correct horse battery');
    }

    public function testUncheckedLoginLeavesNoRememberCookieAndNoStoredToken(): void
    {
        $login = $this->login('alice', 'correct horse battery staple', false);

        self::assertSame(200, $login->status);
        $cookie = (string) $login->headers['Set-Cookie'];
        self::assertStringContainsString(self::COOKIE . '=;', $cookie);
        self::assertStringContainsString('Max-Age=0', $cookie);
        self::assertSame(0, $this->tokenCount());
    }

    public function testRememberedLoginSetsHardenedCookieAndStoresOnlyAHash(): void
    {
        $login = $this->login('alice', 'correct horse battery staple', true);

        self::assertSame(200, $login->status);
        $cookie = (string) $login->headers['Set-Cookie'];
        self::assertStringContainsString('HttpOnly', $cookie);
        self::assertStringContainsString('SameSite=Lax', $cookie);
        self::assertStringContainsString('Max-Age=2592000', $cookie);
        self::assertStringNotContainsString('Secure', $cookie, 'ce test tourne en HTTP local');

        $value = $this->cookieValue($cookie);
        self::assertMatchesRegularExpression('/\A[a-f0-9]{32}\.[a-f0-9]{64}\z/', $value);

        [$selector, $validator] = explode('.', $value, 2);
        $hash = $this->storedHash($selector);
        self::assertIsString($hash);
        self::assertNotSame($validator, $hash, 'le jeton brut ne doit jamais être stocké');
        self::assertSame(hash('sha256', $validator), $hash);
    }

    public function testRememberCookieRestoresTheSessionInAFreshBrowserSession(): void
    {
        $cookie = $this->loginWithRemember('alice', 'correct horse battery staple');

        $response = $this->meWithCookie($cookie);

        self::assertSame(200, $response->status);
        self::assertSame('alice', $this->decode($response)['data']['username']);
    }

    public function testRestoreRegeneratesTheSessionIdentifier(): void
    {
        $cookie = $this->loginWithRemember('alice', 'correct horse battery staple');
        $session = new ArraySession();

        $this->application->kernel($session)->handle($this->request('GET', '/api/auth/me', [
            self::COOKIE => $cookie,
        ]));

        self::assertSame(1, $session->regenerationCount);
    }

    public function testWithoutCookieTheSessionIsNotRestored(): void
    {
        $this->loginWithRemember('alice', 'correct horse battery staple');

        $kernel = $this->application->kernel(new ArraySession());

        self::assertSame(401, $kernel->handle(new Request('GET', '/api/auth/me'))->status);
    }

    public function testForgedValidatorIsRejectedAndTheRealTokenKeepsWorking(): void
    {
        $cookie = $this->loginWithRemember('alice', 'correct horse battery staple');
        [$selector] = explode('.', $cookie, 2);
        $forged = $selector . '.' . str_repeat('a', 64);

        self::assertSame(401, $this->meWithCookie($forged)->status);
        // A mismatched validator must not destroy the row, otherwise anyone
        // holding a selector could lock the real user out.
        self::assertSame(1, $this->tokenCount());
        self::assertSame(200, $this->meWithCookie($cookie)->status);
    }

    public function testMalformedCookieValuesAreIgnored(): void
    {
        $values = ['', 'garbage', 'zz', str_repeat('a', 96), 'a.b', "abc\r\nSet-Cookie: x", 'a.b.c'];
        foreach ($values as $value) {
            $kernel = $this->application->kernel(new ArraySession());
            $response = $kernel->handle($this->request('GET', '/api/auth/me', [self::COOKIE => $value]));

            self::assertSame(401, $response->status, $value);
        }
    }

    public function testEachTokenRestoresOnlyItsOwnUser(): void
    {
        $alice = $this->loginWithRemember('alice', 'correct horse battery staple');
        $bob = $this->loginWithRemember('bob', 'another correct horse battery');
        self::assertSame(2, $this->tokenCount());

        self::assertSame('alice', $this->decode($this->meWithCookie($alice))['data']['username']);
        self::assertSame('bob', $this->decode($this->meWithCookie($bob))['data']['username']);
    }

    public function testDeactivatedUserCannotBeRestoredFromAValidToken(): void
    {
        $cookie = $this->loginWithRemember('alice', 'correct horse battery staple');
        self::assertTrue($this->application->userService->setActive('alice', false));

        self::assertSame(401, $this->meWithCookie($cookie)->status);
        self::assertSame(0, $this->tokenCount(), 'un compte désactivé doit invalider ses jetons');
    }

    public function testLogoutRevokesTheRememberTokenAndClearsTheCookie(): void
    {
        $cookie = $this->loginWithRemember('alice', 'correct horse battery staple');
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $kernel->handle($this->request('GET', '/api/auth/me', [self::COOKIE => $cookie]));
        $csrf = $this->csrfToken($kernel);

        $logout = $kernel->handle($this->request('POST', '/api/auth/logout', [
            self::COOKIE => $cookie,
        ], ['x-csrf-token' => $csrf]));

        self::assertSame(204, $logout->status);
        self::assertStringContainsString('Max-Age=0', (string) $logout->headers['Set-Cookie']);
        self::assertSame(0, $this->tokenCount());
        self::assertSame(401, $this->meWithCookie($cookie)->status);
    }

    public function testPasswordChangeRevokesEveryRememberedDevice(): void
    {
        $first = $this->loginWithRemember('alice', 'correct horse battery staple');
        $second = $this->loginWithRemember('alice', 'correct horse battery staple');
        self::assertSame(2, $this->tokenCount());

        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $kernel->handle($this->request('GET', '/api/auth/me', [self::COOKIE => $first]));
        $csrf = $this->csrfToken($kernel);

        $change = $kernel->handle($this->request('POST', '/api/settings/password', [
            self::COOKIE => $first,
        ], [
            'content-type' => 'application/json',
            'x-csrf-token' => $csrf,
        ], json_encode([
            'current_password' => 'correct horse battery staple',
            'new_password' => 'this is the replacement password',
        ], JSON_THROW_ON_ERROR)));

        self::assertSame(200, $change->status);
        self::assertStringContainsString('Max-Age=0', (string) $change->headers['Set-Cookie']);
        self::assertSame(0, $this->tokenCount());
        self::assertSame(401, $this->meWithCookie($first)->status);
        self::assertSame(401, $this->meWithCookie($second)->status);
    }

    public function testUncheckingRemovesTheStoredTokenNotOnlyTheCookie(): void
    {
        $stale = $this->loginWithRemember('alice', 'correct horse battery staple');
        self::assertSame(1, $this->tokenCount());

        // The browser still sends the old cookie alongside the login POST.
        $login = $this->login('alice', 'correct horse battery staple', false, [self::COOKIE => $stale]);

        self::assertStringContainsString('Max-Age=0', (string) $login->headers['Set-Cookie']);
        self::assertSame(0, $this->tokenCount());
        self::assertSame(401, $this->meWithCookie($stale)->status);
    }

    public function testLoginRejectsANonBooleanRememberFlag(): void
    {
        $kernel = $this->application->kernel(new ArraySession());
        $csrf = $this->csrfToken($kernel);

        $response = $kernel->handle($this->request('POST', '/api/auth/login', [], [
            'content-type' => 'application/json',
            'x-csrf-token' => $csrf,
        ], json_encode([
            'username' => 'alice',
            'password' => 'correct horse battery staple',
            'remember' => 'yes',
        ], JSON_THROW_ON_ERROR), '192.0.2.1'));

        self::assertSame(422, $response->status);
        self::assertArrayHasKey('remember', $this->decode($response)['error']['fields']);
        self::assertSame(0, $this->tokenCount());
    }

    public function testLoginRequestIsNeverAutoAuthenticatedByAPresentCookie(): void
    {
        $cookie = $this->loginWithRemember('alice', 'correct horse battery staple');
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->csrfToken($kernel);
        $regenerationsBefore = $session->regenerationCount;

        // Bob logs in on a browser still holding Alice's cookie: the explicit
        // credentials must win and no extra session may be created.
        $login = $kernel->handle($this->request('POST', '/api/auth/login', [self::COOKIE => $cookie], [
            'content-type' => 'application/json',
            'x-csrf-token' => $csrf,
        ], json_encode([
            'username' => 'bob',
            'password' => 'another correct horse battery',
            'remember' => false,
        ], JSON_THROW_ON_ERROR), '192.0.2.1'));

        self::assertSame(200, $login->status);
        self::assertSame('bob', $this->decode($login)['data']['user']['username']);
        self::assertSame(
            $regenerationsBefore + 1,
            $session->regenerationCount,
            'la connexion ne doit créer qu’une seule session',
        );
    }

    public function testExpiredTokenIsRejectedAndPruned(): void
    {
        $cookie = $this->loginWithRemember('alice', 'correct horse battery staple');
        $this->expireToken($cookie);

        self::assertSame(401, $this->meWithCookie($cookie)->status);
        self::assertSame(0, $this->tokenCount(), 'un jeton expiré doit être supprimé');
    }

    public function testExpiredTokensArePrunedWhenANewTokenIsIssued(): void
    {
        $stale = $this->loginWithRemember('alice', 'correct horse battery staple');
        $this->expireToken($stale);

        $this->loginWithRemember('alice', 'correct horse battery staple');

        self::assertSame(1, $this->tokenCount(), 'seul le nouveau jeton doit subsister');
    }

    public function testTokensAreCascadedWhenTheUserIsDeleted(): void
    {
        $this->loginWithRemember('alice', 'correct horse battery staple');
        self::assertSame(1, $this->tokenCount());

        $this->pdo->exec("DELETE FROM users WHERE username = 'alice'");

        self::assertSame(0, $this->tokenCount());
    }

    /**
     * A deployment that has not run migration 005 must not brick the whole API:
     * every request reads the token table, so an unguarded failure turned each
     * endpoint into a 500 and made even the login form unusable.
     */
    public function testApiKeepsWorkingWhenTheTokenTableIsMissing(): void
    {
        $this->pdo->exec('DROP TABLE user_remember_tokens');

        $kernel = $this->application->kernel(new ArraySession());
        self::assertSame(200, $kernel->handle(new Request('GET', '/api/auth/csrf'))->status);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/auth/me'))->status);

        // A presented cookie resolves to nothing instead of erroring.
        $cookie = str_repeat('a', 32) . '.' . str_repeat('b', 64);
        self::assertSame(401, $kernel->handle(
            $this->request('GET', '/api/auth/me', [self::COOKIE => $cookie])
        )->status);
    }

    public function testLoginAndLogoutStillSucceedWhenTheTokenTableIsMissing(): void
    {
        $this->pdo->exec('DROP TABLE user_remember_tokens');

        // A verified password must not be undone by a persistence failure.
        $login = $this->login('alice', 'correct horse battery staple', true);
        self::assertSame(200, $login->status);
        self::assertSame('alice', $this->decode($login)['data']['user']['username']);
        self::assertStringContainsString(self::COOKIE . '=', (string) $login->headers['Set-Cookie']);

        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->csrfToken($kernel);
        self::assertSame(204, $kernel->handle(
            $this->request('POST', '/api/auth/logout', [], ['x-csrf-token' => $csrf])
        )->status);
    }

    private function loginWithRemember(string $username, string $password): string
    {
        $login = $this->login($username, $password, true);
        self::assertSame(200, $login->status);

        return $this->cookieValue((string) $login->headers['Set-Cookie']);
    }

    private function meWithCookie(string $cookie): Response
    {
        return $this->application->kernel(new ArraySession())
            ->handle($this->request('GET', '/api/auth/me', [self::COOKIE => $cookie]));
    }

    /** @param array<string, string> $cookies */
    private function login(
        string $username,
        string $password,
        bool $remember,
        array $cookies = [],
    ): Response {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->csrfToken($kernel);

        return $kernel->handle($this->request('POST', '/api/auth/login', $cookies, [
            'content-type' => 'application/json',
            'x-csrf-token' => $csrf,
        ], json_encode([
            'username' => $username,
            'password' => $password,
            'remember' => $remember,
        ], JSON_THROW_ON_ERROR), '192.0.2.1'));
    }

    private function csrfToken(ApiKernel $kernel): string
    {
        $response = $kernel->handle($this->request('GET', '/api/auth/csrf'));
        $token = $this->decode($response)['data']['csrf_token'] ?? null;
        self::assertIsString($token);

        return $token;
    }

    /** @param array<string, string> $cookies
     *  @param array<string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        array $cookies = [],
        array $headers = [],
        string $body = '',
        string $remoteAddress = '',
    ): Request {
        return new Request($method, $path, $headers, $body, $remoteAddress, [], [], $cookies);
    }

    private function cookieValue(string $setCookie): string
    {
        $value = substr(explode(';', $setCookie)[0], strlen(self::COOKIE) + 1);
        self::assertNotSame('', $value);

        return $value;
    }

    private function storedHash(string $selector): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT token_hash FROM user_remember_tokens WHERE selector = :selector'
        );
        $statement->execute(['selector' => $selector]);
        $value = $statement->fetchColumn();

        return is_string($value) ? $value : null;
    }

    private function tokenCount(): int
    {
        $count = $this->pdo->query('SELECT COUNT(*) FROM user_remember_tokens');
        self::assertNotFalse($count);

        return (int) $count->fetchColumn();
    }

    private function expireToken(string $cookie): void
    {
        [$selector] = explode('.', $cookie, 2);
        $statement = $this->pdo->prepare(
            "UPDATE user_remember_tokens SET expires_at = '2000-01-01T00:00:00Z' WHERE selector = :selector"
        );
        $statement->execute(['selector' => $selector]);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
