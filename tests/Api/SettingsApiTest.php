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

final class SettingsApiTest extends TestCase
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

    public function testAuthenticationIsRequiredForSettingsRoutes(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->decode($kernel->handle(new Request('GET', '/api/auth/csrf')))['data']['csrf_token'];
        self::assertIsString($csrf);

        self::assertSame(401, $kernel->handle(new Request('GET', '/api/settings'))->status);
        self::assertSame(401, $kernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"articles_per_page":50}',
        ))->status);
    }

    public function testUserCanReadAndUpdateArticlesPerPage(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $initial = $kernel->handle(new Request('GET', '/api/settings'));
        self::assertSame(200, $initial->status);
        self::assertSame(['articles_per_page' => 25, 'theme' => 'light'], $this->decode($initial)['data']);

        foreach ([10, 25, 50, 100] as $pageSize) {
            $updated = $kernel->handle(new Request(
                'PATCH',
                '/api/settings',
                ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
                json_encode(['articles_per_page' => $pageSize], JSON_THROW_ON_ERROR),
            ));
            self::assertSame(200, $updated->status);
            self::assertSame($pageSize, $this->decode($updated)['data']['articles_per_page']);
        }

        $settings = $this->decode($kernel->handle(new Request('GET', '/api/settings')));
        self::assertSame(100, $settings['data']['articles_per_page']);
        $articles = $this->decode($kernel->handle(new Request('GET', '/api/articles')));
        self::assertSame(100, $articles['pagination']['per_page']);
    }

    public function testSettingsRemainStrictlyIsolatedBetweenUsers(): void
    {
        [$aliceKernel, $aliceCsrf, $aliceId] = $this->authenticatedKernel(
            'alice',
            'correct horse battery staple',
        );
        [$bobKernel, , $bobId] = $this->authenticatedKernel('bob', 'another correct horse battery');

        self::assertSame(200, $aliceKernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $aliceCsrf],
            '{"articles_per_page":50}',
        ))->status);

        self::assertSame(50, $this->decode(
            $aliceKernel->handle(new Request('GET', '/api/settings'))
        )['data']['articles_per_page']);
        self::assertSame(25, $this->decode(
            $bobKernel->handle(new Request('GET', '/api/settings'))
        )['data']['articles_per_page']);
        self::assertSame(50, $this->storedPageSize($aliceId));
        self::assertSame(25, $this->storedPageSize($bobId));
    }

    public function testUpdateRequiresCsrfAndRejectsInvalidPayloads(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        self::assertSame(403, $kernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json'],
            '{"articles_per_page":50}',
        ))->status);

        foreach ([0, 20, 101] as $invalidPageSize) {
            self::assertSame(422, $kernel->handle(new Request(
                'PATCH',
                '/api/settings',
                ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
                json_encode(['articles_per_page' => $invalidPageSize], JSON_THROW_ON_ERROR),
            ))->status);
        }
        self::assertSame(422, $kernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"articles_per_page":"50"}',
        ))->status);
        self::assertSame(422, $kernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{}',
        ))->status);
        self::assertSame(422, $kernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"theme":"noir"}',
        ))->status);
        self::assertSame(25, $this->decode(
            $kernel->handle(new Request('GET', '/api/settings'))
        )['data']['articles_per_page']);
    }

    public function testUserCanReadAndUpdateTheme(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $initial = $kernel->handle(new Request('GET', '/api/settings'));
        self::assertSame(200, $initial->status);
        self::assertSame(['articles_per_page' => 25, 'theme' => 'light'], $this->decode($initial)['data']);

        $updated = $kernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"theme":"dark"}',
        ));
        self::assertSame(200, $updated->status);
        self::assertSame(['articles_per_page' => 25, 'theme' => 'dark'], $this->decode($updated)['data']);

        $combined = $kernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"theme":"light","articles_per_page":50}',
        ));
        self::assertSame(200, $combined->status);
        self::assertSame(['articles_per_page' => 50, 'theme' => 'light'], $this->decode($combined)['data']);

        self::assertSame('light', $this->storedTheme($this->decode(
            $kernel->handle(new Request('GET', '/api/auth/me'))
        )['data']['id']));
    }

    public function testThemeRemainsStrictlyIsolatedBetweenUsers(): void
    {
        [$aliceKernel, $aliceCsrf, $aliceId] = $this->authenticatedKernel(
            'alice',
            'correct horse battery staple',
        );
        [$bobKernel, , $bobId] = $this->authenticatedKernel('bob', 'another correct horse battery');

        self::assertSame(200, $aliceKernel->handle(new Request(
            'PATCH',
            '/api/settings',
            ['content-type' => 'application/json', 'x-csrf-token' => $aliceCsrf],
            '{"theme":"dark"}',
        ))->status);

        self::assertSame('dark', $this->decode(
            $aliceKernel->handle(new Request('GET', '/api/settings'))
        )['data']['theme']);
        self::assertSame('light', $this->decode(
            $bobKernel->handle(new Request('GET', '/api/settings'))
        )['data']['theme']);
        self::assertSame('light', $this->storedTheme($bobId));
    }

    /** @return array{ApiKernel, string, int} */
    private function authenticatedKernel(string $username, string $password): array
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session);
        $csrf = $this->decode($kernel->handle(new Request('GET', '/api/auth/csrf')))['data']['csrf_token'];
        self::assertIsString($csrf);
        $login = $kernel->handle(new Request(
            'POST',
            '/api/auth/login',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            json_encode(['username' => $username, 'password' => $password], JSON_THROW_ON_ERROR),
            '192.0.2.10',
        ));
        self::assertSame(200, $login->status);
        $data = $this->decode($login)['data'];
        self::assertIsString($data['csrf_token']);
        self::assertIsInt($data['user']['id']);

        return [$kernel, $data['csrf_token'], $data['user']['id']];
    }

    private function storedPageSize(int $userId): int
    {
        $statement = $this->pdo->prepare(
            'SELECT articles_per_page FROM user_settings WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    private function storedTheme(int $userId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT theme FROM user_settings WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
