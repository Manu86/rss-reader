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

final class ArticleApiTest extends TestCase
{
    private PDO $pdo;
    private TestApplication $application;
    private int $aliceId;
    private int $bobId;
    private int $techCategoryId;
    private int $aliceFeedId;
    private int $uncategorizedFeedId;
    private int $bobFeedId;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        $this->application = new TestApplication($this->pdo, dirname(__DIR__, 2) . '/migrations');
        $this->aliceId = $this->application->userService
            ->create('alice', 'correct horse battery staple')->id;
        $this->bobId = $this->application->userService
            ->create('bob', 'another correct horse battery')->id;
        $this->techCategoryId = $this->insertCategory($this->aliceId, 'Technique');
        $this->aliceFeedId = $this->insertFeed($this->aliceId, $this->techCategoryId, 'Flux Alice');
        $this->uncategorizedFeedId = $this->insertFeed($this->aliceId, null, 'Sans catégorie');
        $this->bobFeedId = $this->insertFeed($this->bobId, null, 'Flux Bob');
    }

    public function testAuthenticationIsRequired(): void
    {
        $kernel = $this->application->kernel(new ArraySession());

        self::assertSame(401, $kernel->handle(new Request('GET', '/api/articles'))->status);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/articles/1'))->status);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/search', query: ['q' => 'php']))->status);
        self::assertSame(401, $kernel->handle(new Request('GET', '/api/counts'))->status);
    }

    public function testSearchCoversEveryIndexedFieldAndNeverLeaksAnotherUser(): void
    {
        $title = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'PHP moderne',
            null,
            '2026-09-24T12:00:00Z',
            false,
            false,
        );
        $summary = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Base locale',
            null,
            '2026-09-24T11:00:00Z',
            false,
            false,
        );
        $content = $this->insertArticle(
            $this->aliceId,
            $this->uncategorizedFeedId,
            'Format de flux',
            null,
            '2026-09-24T10:00:00Z',
            true,
            true,
        );
        $author = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Chronique',
            null,
            '2026-09-24T09:00:00Z',
            false,
            false,
        );
        $bobSecret = $this->insertArticle(
            $this->bobId,
            $this->bobFeedId,
            'PHP secret Bob',
            null,
            '2026-09-25T12:00:00Z',
            false,
            false,
        );
        $this->updateArticleText($summary, summary: 'Stockage SQLite robuste');
        $this->updateArticleText($content, content: 'Lecture complète des documents Atom');
        $this->updateArticleText($author, author: 'Camille Dupont');
        [$kernel] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        self::assertSame([$title], $this->searchIds($kernel, 'php'));
        self::assertSame([$summary], $this->searchIds($kernel, 'sql'));
        self::assertSame([$content], $this->searchIds($kernel, 'atom'));
        self::assertSame([$author], $this->searchIds($kernel, 'camille'));
        self::assertNotContains($bobSecret, $this->searchIds($kernel, 'php'));

        self::assertSame([], $this->searchIds($kernel, 'php OR secret'));
        self::assertSame(422, $kernel->handle(new Request(
            'GET',
            '/api/search',
            query: ['q' => '" + -*'],
        ))->status);
    }

    public function testSearchSupportsFiltersPaginationAndDeletionConsistency(): void
    {
        $ids = [];
        for ($index = 1; $index <= 12; ++$index) {
            $ids[] = $this->insertArticle(
                $this->aliceId,
                $index === 12 ? $this->uncategorizedFeedId : $this->aliceFeedId,
                'Recherche commune ' . $index,
                null,
                sprintf('2026-09-24T%02d:00:00Z', $index),
                $index === 11,
                $index === 10,
            );
        }
        [$kernel] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $page = $this->decode($kernel->handle(new Request(
            'GET',
            '/api/search',
            query: ['q' => 'recherche', 'page' => '2', 'per_page' => '10'],
        )));
        self::assertCount(2, $page['data']);
        self::assertSame(12, $page['pagination']['total_items']);
        self::assertSame(2, $page['pagination']['total_pages']);
        self::assertCount(11, $this->searchIds($kernel, 'recherche', ['filter' => 'unread']));
        self::assertSame([$ids[9]], $this->searchIds($kernel, 'recherche', ['filter' => 'favorites']));
        self::assertSame([$ids[11]], $this->searchIds($kernel, 'recherche', ['category' => 'uncategorized']));

        $this->pdo->exec('DELETE FROM articles WHERE id = ' . $ids[0]);
        self::assertCount(11, $this->searchIds($kernel, 'recherche'));
    }

    public function testCountsIncludeMainViewsCategoriesFeedsAndUncategorized(): void
    {
        $emptyCategory = $this->insertCategory($this->aliceId, 'Vide');
        $emptyFeed = $this->insertFeed($this->aliceId, $emptyCategory, 'Flux vide');
        $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Non lu technique',
            null,
            '2026-09-24T12:00:00Z',
            false,
            true,
        );
        $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Lu technique',
            null,
            '2026-09-24T11:00:00Z',
            true,
            true,
        );
        $this->insertArticle(
            $this->aliceId,
            $this->uncategorizedFeedId,
            'Non lu sans catégorie',
            null,
            '2026-09-24T10:00:00Z',
            false,
            false,
        );
        $this->insertArticle(
            $this->bobId,
            $this->bobFeedId,
            'Non lu Bob',
            null,
            '2026-09-25T10:00:00Z',
            false,
            true,
        );
        [$kernel] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $data = $this->decode($kernel->handle(new Request('GET', '/api/counts')))['data'];

        self::assertSame(3, $data['all']);
        self::assertSame(2, $data['unread']);
        self::assertSame(1, $data['read']);
        self::assertSame(2, $data['favorites']);
        self::assertSame(1, $data['uncategorized']);
        self::assertSame([
            ['category_id' => $this->techCategoryId, 'unread' => 1],
            ['category_id' => $emptyCategory, 'unread' => 0],
        ], $data['categories']);
        self::assertSame([
            ['feed_id' => $this->aliceFeedId, 'unread' => 1],
            ['feed_id' => $emptyFeed, 'unread' => 0],
            ['feed_id' => $this->uncategorizedFeedId, 'unread' => 1],
        ], $data['feeds']);
    }

    public function testListingIsPaginatedOrderedCompactAndDoesNotMarkArticlesRead(): void
    {
        $older = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Publié',
            '2026-09-24T11:00:00Z',
            '2026-09-24T09:00:00Z',
            false,
            true,
        );
        $newest = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Sans date',
            null,
            '2026-09-24T12:00:00Z',
            false,
            false,
        );
        $read = $this->insertArticle(
            $this->aliceId,
            $this->uncategorizedFeedId,
            'Déjà lu',
            '2026-09-24T10:00:00Z',
            '2026-09-24T08:00:00Z',
            true,
            true,
        );
        $this->insertArticle(
            $this->bobId,
            $this->bobFeedId,
            'Secret Bob',
            '2026-09-25T10:00:00Z',
            '2026-09-25T10:00:00Z',
            false,
            false,
        );
        $this->pdo->exec('UPDATE user_settings SET articles_per_page = 10 WHERE user_id = ' . $this->aliceId);
        [$kernel] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $response = $kernel->handle(new Request('GET', '/api/articles'));

        self::assertSame(200, $response->status);
        $payload = $this->decode($response);
        self::assertSame([$newest, $older, $read], array_column($payload['data'], 'id'));
        self::assertSame([
            'page' => 1,
            'per_page' => 10,
            'total_items' => 3,
            'total_pages' => 1,
        ], $payload['pagination']);
        self::assertArrayNotHasKey('content', $payload['data'][0]);
        self::assertSame('Flux Alice', $payload['data'][0]['feed']['name']);
        self::assertSame([
            'id' => $this->techCategoryId,
            'name' => 'Technique',
        ], $payload['data'][0]['feed']['category']);
        self::assertSame('/api/feeds/' . $this->aliceFeedId . '/favicon', $payload['data'][0]['feed']['favicon_url']);
        self::assertSame('/api/articles/' . $newest . '/image', $payload['data'][0]['image_url']);
        self::assertSame(2, $this->countUnread($this->aliceId));
    }

    public function testFiltersAreScopedAndOwnedResourcesAreValidated(): void
    {
        $unreadFavorite = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Non lu favori',
            '2026-09-24T12:00:00Z',
            '2026-09-24T12:00:00Z',
            false,
            true,
        );
        $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Lu',
            '2026-09-24T11:00:00Z',
            '2026-09-24T11:00:00Z',
            true,
            false,
        );
        $uncategorized = $this->insertArticle(
            $this->aliceId,
            $this->uncategorizedFeedId,
            'Sans catégorie',
            '2026-09-24T10:00:00Z',
            '2026-09-24T10:00:00Z',
            false,
            false,
        );
        [$kernel] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        self::assertSame([$unreadFavorite, $uncategorized], $this->ids($kernel, ['filter' => 'unread']));
        self::assertSame([$unreadFavorite], $this->ids($kernel, ['filter' => 'favorites']));
        self::assertCount(2, $this->ids($kernel, ['category_id' => (string) $this->techCategoryId]));
        self::assertSame([$uncategorized], $this->ids($kernel, ['category' => 'uncategorized']));
        $uncategorizedPayload = $this->decode($kernel->handle(new Request(
            'GET',
            '/api/articles',
            query: ['category' => 'uncategorized'],
        )));
        self::assertNull($uncategorizedPayload['data'][0]['feed']['category']);
        self::assertCount(2, $this->ids($kernel, ['feed_id' => (string) $this->aliceFeedId]));

        self::assertSame(404, $kernel->handle(new Request(
            'GET',
            '/api/articles',
            query: ['feed_id' => (string) $this->bobFeedId],
        ))->status);
        $bobCategory = $this->insertCategory($this->bobId, 'Privée');
        self::assertSame(404, $kernel->handle(new Request(
            'GET',
            '/api/articles',
            query: ['category_id' => (string) $bobCategory],
        ))->status);
    }

    public function testDetailIsReadOnlyAndPatchChangesStateWithCsrfProtection(): void
    {
        $articleId = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'À lire',
            null,
            '2026-09-24T12:00:00Z',
            false,
            false,
        );
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $detail = $kernel->handle(new Request('GET', '/api/articles/' . $articleId));

        self::assertSame(200, $detail->status);
        $data = $this->decode($detail)['data'];
        self::assertFalse($data['is_read']);
        self::assertSame('Contenu complet ' . $articleId, $data['content']);
        self::assertSame(1, $this->countUnread($this->aliceId));

        self::assertSame(403, $kernel->handle(new Request(
            'PATCH',
            '/api/articles/' . $articleId,
            ['content-type' => 'application/json'],
            '{"is_favorite":true}',
        ))->status);
        $read = $kernel->handle(new Request(
            'PATCH',
            '/api/articles/' . $articleId,
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"is_read":true}',
        ));
        self::assertSame(200, $read->status);
        self::assertTrue($this->decode($read)['data']['is_read']);
        self::assertSame(0, $this->countUnread($this->aliceId));

        $updated = $kernel->handle(new Request(
            'PATCH',
            '/api/articles/' . $articleId,
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"is_read":false,"is_favorite":true}',
        ));
        self::assertSame(200, $updated->status);
        self::assertFalse($this->decode($updated)['data']['is_read']);
        self::assertTrue($this->decode($updated)['data']['is_favorite']);
        self::assertSame(1, $this->countUnread($this->aliceId));
    }

    public function testCrossUserArticleAccessReturnsNotFoundAndListingNeverLeaks(): void
    {
        $aliceArticle = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Privé Alice',
            null,
            '2026-09-24T12:00:00Z',
            false,
            false,
        );
        $bobArticle = $this->insertArticle(
            $this->bobId,
            $this->bobFeedId,
            'Privé Bob',
            null,
            '2026-09-24T13:00:00Z',
            false,
            false,
        );
        [$alice, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        self::assertSame([$aliceArticle], $this->ids($alice));
        self::assertSame(404, $alice->handle(new Request('GET', '/api/articles/' . $bobArticle))->status);
        self::assertSame(404, $alice->handle(new Request(
            'PATCH',
            '/api/articles/' . $bobArticle,
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"is_read":true}',
        ))->status);
        self::assertSame(1, $this->countUnread($this->bobId));
    }

    public function testPaginationAndInputValidation(): void
    {
        for ($index = 1; $index <= 12; ++$index) {
            $this->insertArticle(
                $this->aliceId,
                $this->aliceFeedId,
                'Article ' . $index,
                null,
                sprintf('2026-09-24T%02d:00:00Z', $index),
                false,
                false,
            );
        }
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple');
        $page = $this->decode($kernel->handle(new Request(
            'GET',
            '/api/articles',
            query: ['page' => '2', 'per_page' => '10'],
        )));
        self::assertCount(2, $page['data']);
        self::assertSame(12, $page['pagination']['total_items']);
        self::assertSame(2, $page['pagination']['total_pages']);

        foreach ([
            ['filter' => 'secret'],
            ['per_page' => '11'],
            ['page' => '0'],
            ['category' => 'other'],
            ['category' => 'uncategorized', 'category_id' => (string) $this->techCategoryId],
            ['unknown' => 'value'],
        ] as $query) {
            self::assertSame(422, $kernel->handle(new Request('GET', '/api/articles', query: $query))->status);
        }
        self::assertSame(422, $kernel->handle(new Request(
            'PATCH',
            '/api/articles/1',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{}',
        ))->status);
        self::assertSame(422, $kernel->handle(new Request(
            'PATCH',
            '/api/articles/1',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"is_read":"yes"}',
        ))->status);
    }

    public function testRecommendationsComeOnlyFromOwnUnreadArticlesAndSignalData(): void
    {
        $favorite = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'PHP moderne',
            null,
            '2026-09-24T12:00:00Z',
            true,
            true,
        );
        $matching = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Nouvelle version de PHP publiée',
            null,
            '2026-09-24T13:00:00Z',
            false,
            false,
        );
        $unreadFavorite = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'PHP non lu favori',
            null,
            '2026-09-24T14:00:00Z',
            false,
            true,
        );
        $unrelated = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Recette de tarte',
            null,
            '2026-09-24T15:00:00Z',
            false,
            false,
        );
        $bobPrivate = $this->insertArticle(
            $this->bobId,
            $this->bobFeedId,
            'PHP secret privé',
            null,
            '2026-09-24T16:00:00Z',
            false,
            false,
        );
        [$kernel] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $response = $kernel->handle(new Request('GET', '/api/recommendations'));
        self::assertSame(200, $response->status);
        $ids = array_map(
            static fn(mixed $article): int => (int) $article['id'],
            $this->decode($response)['data'],
        );

        self::assertContains($matching, $ids);
        self::assertNotContains($favorite, $ids);
        self::assertNotContains($unreadFavorite, $ids);
        self::assertNotContains($unrelated, $ids);
        self::assertNotContains($bobPrivate, $ids);

        // Sans favoris de signal (historique vide), la réponse reste vide.
        $this->pdo->exec("UPDATE articles SET is_favorite = 0 WHERE user_id = {$this->aliceId}");
        $response = $kernel->handle(new Request('GET', '/api/recommendations'));
        self::assertSame(200, $response->status);
        self::assertSame([], $this->decode($response)['data']);

        self::assertSame(401, (new TestApplication($this->pdo, dirname(__DIR__, 2) . '/migrations'))
            ->kernel(new ArraySession())
            ->handle(new Request('GET', '/api/recommendations'))->status);
    }

    public function testRecommendationsSupportCategoryScoping(): void
    {
        $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'PHP moderne',
            null,
            '2026-09-24T12:00:00Z',
            true,
            true,
        );
        $inCategory = $this->insertArticle(
            $this->aliceId,
            $this->aliceFeedId,
            'Nouvelle version de PHP publiée',
            null,
            '2026-09-24T13:00:00Z',
            false,
            false,
        );
        $withoutCategory = $this->insertArticle(
            $this->aliceId,
            $this->uncategorizedFeedId,
            'Nouvelle version de PHP présentée',
            null,
            '2026-09-24T14:00:00Z',
            false,
            false,
        );
        $bobPrivate = $this->insertArticle(
            $this->bobId,
            $this->bobFeedId,
            'Bob PHP',
            null,
            '2026-09-24T15:00:00Z',
            false,
            false,
        );
        [$kernel] = $this->authenticatedKernel('alice', 'correct horse battery staple');

        $ids = fn(array $query): array => array_map(
            static fn(mixed $article): int => (int) $article['id'],
            $this->decode($kernel->handle(new Request(
                'GET',
                '/api/recommendations',
                query: $query,
            )))['data'],
        );

        self::assertContains($inCategory, $ids(['category_id' => (string) $this->techCategoryId]));
        self::assertNotContains($withoutCategory, $ids(['category_id' => (string) $this->techCategoryId]));
        self::assertNotContains($bobPrivate, $ids(['category_id' => (string) $this->techCategoryId]));

        self::assertContains($withoutCategory, $ids(['category' => 'uncategorized']));
        self::assertNotContains($inCategory, $ids(['category' => 'uncategorized']));
        self::assertNotContains($bobPrivate, $ids(['category' => 'uncategorized']));
        self::assertContains($inCategory, $ids([]));
        self::assertContains($withoutCategory, $ids([]));

        foreach ([
            ['category_id' => '0'],
            ['category_id' => '999999'],
            ['category' => 'other'],
            ['category' => 'uncategorized', 'category_id' => '1'],
            ['unknown' => 'value'],
        ] as $query) {
            self::assertContains(
                $kernel->handle(new Request('GET', '/api/recommendations', query: $query))->status,
                [404, 422],
            );
        }

        // L'interface transmet un marqueur de pagination ignoré ; il ne doit pas casser la réponse.
        self::assertSame(200, $kernel->handle(new Request(
            'GET',
            '/api/recommendations',
            query: ['page' => '1'],
        ))->status);
    }

    /** @return array{ApiKernel, string} */
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
        $rotated = $this->decode($login)['data']['csrf_token'];
        self::assertIsString($rotated);

        return [$kernel, $rotated];
    }

    /** @param array<string, mixed> $query
     *  @return list<int>
     */
    private function ids(ApiKernel $kernel, array $query = []): array
    {
        $payload = $this->decode($kernel->handle(new Request('GET', '/api/articles', query: $query)));

        return array_map(static fn(mixed $id): int => (int) $id, array_column($payload['data'], 'id'));
    }

    /** @param array<string, string> $parameters
     *  @return list<int>
     */
    private function searchIds(ApiKernel $kernel, string $query, array $parameters = []): array
    {
        $payload = $this->decode($kernel->handle(new Request(
            'GET',
            '/api/search',
            query: ['q' => $query] + $parameters,
        )));

        return array_map(static fn(mixed $id): int => (int) $id, array_column($payload['data'], 'id'));
    }

    private function updateArticleText(
        int $articleId,
        ?string $summary = null,
        ?string $content = null,
        ?string $author = null,
    ): void {
        $statement = $this->pdo->prepare(
            'UPDATE articles SET summary = COALESCE(:summary, summary), '
            . 'content = COALESCE(:content, content), author = COALESCE(:author, author) WHERE id = :id'
        );
        $statement->execute([
            'summary' => $summary,
            'content' => $content,
            'author' => $author,
            'id' => $articleId,
        ]);
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
            'created_at' => '2026-09-24T08:00:00Z',
            'updated_at' => '2026-09-24T08:00:00Z',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertFeed(int $userId, ?int $categoryId, string $name): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(user_id, category_id, name, feed_url, favicon_path, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:user_id, :category_id, :name, :feed_url, :favicon_path, 1, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => $name,
            'feed_url' => 'https://feeds.test/' . $userId . '/' . bin2hex(random_bytes(4)),
            'favicon_path' => $userId === $this->aliceId ? 'u' . $userId . '/fake/favicon.png' : null,
            'status' => 'success',
            'created_at' => '2026-09-24T08:00:00Z',
            'updated_at' => '2026-09-24T08:00:00Z',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function insertArticle(
        int $userId,
        int $feedId,
        string $title,
        ?string $publishedAt,
        string $discoveredAt,
        bool $read,
        bool $favorite,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles '
            . '(user_id, feed_id, title, url, author, published_at, discovered_at, summary, content, '
            . 'image_path, is_read, is_favorite, deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :title, :url, :author, :published_at, :discovered_at, '
            . ':summary, :content, :image_path, :is_read, :is_favorite, :hash, :created_at, :updated_at)'
        );
        $hash = hash('sha256', $userId . ':' . $feedId . ':' . $title . ':' . random_bytes(4));
        $statement->execute([
            'user_id' => $userId,
            'feed_id' => $feedId,
            'title' => $title,
            'url' => 'https://site.test/article/' . $hash,
            'author' => 'Auteur',
            'published_at' => $publishedAt,
            'discovered_at' => $discoveredAt,
            'summary' => 'Résumé ' . $title,
            'content' => 'pending',
            'image_path' => $userId === $this->aliceId ? 'u' . $userId . '/fake/image.png' : null,
            'is_read' => $read ? 1 : 0,
            'is_favorite' => $favorite ? 1 : 0,
            'hash' => $hash,
            'created_at' => $discoveredAt,
            'updated_at' => $discoveredAt,
        ]);
        $id = (int) $this->pdo->lastInsertId();
        $update = $this->pdo->prepare('UPDATE articles SET content = :content WHERE id = :id');
        $update->execute(['content' => 'Contenu complet ' . $id, 'id' => $id]);

        return $id;
    }

    private function countUnread(int $userId): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM articles WHERE user_id = :user_id AND is_read = 0');
        $statement->execute(['user_id' => $userId]);

        return (int) $statement->fetchColumn();
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
