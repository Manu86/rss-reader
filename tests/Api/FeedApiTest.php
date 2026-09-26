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

final class FeedApiTest extends TestCase
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

    public function testAuthenticationIsRequiredToListFeeds(): void
    {
        $response = $this->application->kernel(new ArraySession())->handle(
            new Request('GET', '/api/feeds'),
        );

        self::assertSame(401, $response->status);
    }

    public function testUserCanCreateShowListFilterAndUpdateLocalFeed(): void
    {
        [$kernel, $csrf, $userId] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        $categoryId = $this->insertCategory($userId, 'Technique');

        $created = $this->createFeed(
            $kernel,
            $csrf,
            '  Actualités PHP  ',
            'HTTPS://Example.Org:443/feed.xml#ignored',
            $categoryId,
        );
        self::assertSame(201, $created->status);
        $feed = $this->decode($created)['data'];
        self::assertSame('Actualités PHP', $feed['name']);
        self::assertSame('https://example.org/feed.xml', $feed['feed_url']);
        self::assertSame('success', $feed['last_fetch_status']);
        self::assertSame('2026-09-24T12:00:00Z', $feed['last_fetch_attempt_at']);
        self::assertTrue($feed['is_active']);
        $feedId = $feed['id'];
        self::assertIsInt($feedId);

        $shown = $this->decode($kernel->handle(new Request('GET', '/api/feeds/' . $feedId)));
        self::assertSame($feedId, $shown['data']['id']);

        $listed = $this->decode($kernel->handle(new Request(
            'GET',
            '/api/feeds',
            query: ['category_id' => (string) $categoryId, 'active' => 'true'],
        )));
        self::assertCount(1, $listed['data']);

        $updated = $kernel->handle(new Request(
            'PATCH',
            '/api/feeds/' . $feedId,
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            json_encode([
                'name' => 'PHP désactivé',
                'category_id' => null,
                'is_active' => false,
            ], JSON_THROW_ON_ERROR),
        ));
        self::assertSame(200, $updated->status);
        $updatedFeed = $this->decode($updated)['data'];
        self::assertSame('PHP désactivé', $updatedFeed['name']);
        self::assertNull($updatedFeed['category_id']);
        self::assertFalse($updatedFeed['is_active']);

        $activeFeeds = $this->decode($kernel->handle(new Request(
            'GET',
            '/api/feeds',
            query: ['active' => '1'],
        )));
        self::assertSame([], $activeFeeds['data']);
        $inactiveFeeds = $this->decode($kernel->handle(new Request(
            'GET',
            '/api/feeds',
            query: ['active' => 'false'],
        )));
        self::assertCount(1, $inactiveFeeds['data']);
    }

    public function testUsersCannotReadUpdateDeleteOrAssignEachOthersResources(): void
    {
        [$aliceKernel, $aliceCsrf, $aliceId] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        [$bobKernel, $bobCsrf, $bobId] = $this->authenticatedKernel('bob', 'another correct horse battery');
        $aliceCategory = $this->insertCategory($aliceId, 'Privée');
        $created = $this->decode($this->createFeed(
            $aliceKernel,
            $aliceCsrf,
            'Flux privé',
            'https://example.org/private.xml',
            $aliceCategory,
        ));
        $feedId = $created['data']['id'];
        self::assertIsInt($feedId);

        self::assertSame(404, $bobKernel->handle(new Request('GET', '/api/feeds/' . $feedId))->status);
        self::assertSame(404, $bobKernel->handle(new Request(
            'PATCH',
            '/api/feeds/' . $feedId,
            ['content-type' => 'application/json', 'x-csrf-token' => $bobCsrf],
            '{"name":"Volé"}',
        ))->status);
        self::assertSame(404, $bobKernel->handle(new Request(
            'DELETE',
            '/api/feeds/' . $feedId,
            ['x-csrf-token' => $bobCsrf],
        ))->status);

        $foreignCategory = $this->createFeed(
            $bobKernel,
            $bobCsrf,
            'Mauvaise catégorie',
            'https://example.net/feed.xml',
            $aliceCategory,
        );
        self::assertSame(404, $foreignCategory->status);

        $aliceList = $this->decode($aliceKernel->handle(new Request('GET', '/api/feeds')));
        $bobList = $this->decode($bobKernel->handle(new Request('GET', '/api/feeds')));
        self::assertCount(1, $aliceList['data']);
        self::assertSame([], $bobList['data']);
        self::assertNotSame($aliceId, $bobId);
    }

    public function testDuplicateIsRejectedPerUserButAllowedForAnotherUser(): void
    {
        [$aliceKernel, $aliceCsrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        [$bobKernel, $bobCsrf] = $this->authenticatedKernel('bob', 'another correct horse battery');
        $this->createFeed($aliceKernel, $aliceCsrf, 'Premier', 'https://EXAMPLE.org:443/feed', null);

        $duplicate = $this->createFeed(
            $aliceKernel,
            $aliceCsrf,
            'Doublon',
            'https://example.org/feed',
            null,
        );
        self::assertSame(409, $duplicate->status);
        self::assertSame('FEED_ALREADY_EXISTS', $this->decode($duplicate)['error']['code']);

        self::assertSame(201, $this->createFeed(
            $bobKernel,
            $bobCsrf,
            'Indépendant',
            'https://example.org/feed',
            null,
        )->status);
    }

    public function testDeletionCascadesArticlesAndRequiresCsrf(): void
    {
        [$kernel, $csrf, $userId] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        $created = $this->decode($this->createFeed(
            $kernel,
            $csrf,
            'À supprimer',
            'https://example.org/delete.xml',
            null,
        ));
        $feedId = $created['data']['id'];
        self::assertIsInt($feedId);
        $this->insertArticle($userId, $feedId);

        self::assertSame(403, $kernel->handle(new Request(
            'DELETE',
            '/api/feeds/' . $feedId,
        ))->status);
        $this->pdo->exec('PRAGMA foreign_keys = OFF');
        self::assertSame(204, $kernel->handle(new Request(
            'DELETE',
            '/api/feeds/' . $feedId,
            ['x-csrf-token' => $csrf],
        ))->status);
        $articles = $this->pdo->query('SELECT COUNT(*) FROM articles');
        self::assertNotFalse($articles);
        self::assertSame(0, (int) $articles->fetchColumn());
    }

    public function testCreateAndUpdateValidateInputAndRejectFeedUrlChanges(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        $invalid = $this->createFeed($kernel, $csrf, '', 'file:///etc/passwd', null);
        self::assertSame(422, $invalid->status);

        $created = $this->decode($this->createFeed(
            $kernel,
            $csrf,
            'Valide',
            'https://example.org/feed.xml',
            null,
        ));
        $feedId = $created['data']['id'];
        self::assertIsInt($feedId);
        $changeUrl = $kernel->handle(new Request(
            'PATCH',
            '/api/feeds/' . $feedId,
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"feed_url":"https://example.net/other.xml"}',
        ));
        self::assertSame(422, $changeUrl->status);
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
        $loginData = $this->decode($login)['data'];
        $rotatedCsrf = $loginData['csrf_token'];
        $userId = $loginData['user']['id'];
        self::assertIsString($rotatedCsrf);
        self::assertIsInt($userId);

        return [$kernel, $rotatedCsrf, $userId];
    }

    private function createFeed(
        ApiKernel $kernel,
        string $csrf,
        string $name,
        string $feedUrl,
        ?int $categoryId,
    ): Response {
        return $kernel->handle(new Request(
            'POST',
            '/api/feeds',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            json_encode([
                'name' => $name,
                'feed_url' => $feedUrl,
                'category_id' => $categoryId,
            ], JSON_THROW_ON_ERROR),
        ));
    }

    private function insertCategory(int $userId, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (user_id, name, created_at, updated_at) '
            . 'VALUES (:user_id, :name, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertArticle(int $userId, int $feedId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles '
            . '(user_id, feed_id, title, discovered_at, deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :title, :discovered_at, :hash, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'feed_id' => $feedId,
            'title' => 'Article local',
            'discovered_at' => '2026-09-24T12:00:00Z',
            'hash' => 'local-article',
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
