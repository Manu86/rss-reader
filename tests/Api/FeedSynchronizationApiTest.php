<?php

declare(strict_types=1);

namespace Tests\Api;

use App\Database\ConnectionFactory;
use App\Http\ApiKernel;
use App\Http\Request;
use App\Http\Response;
use App\Http\SafeHttpClient;
use App\Http\TransportResponse;
use App\Http\UrlResolver;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Validation\UrlNormalizer;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\ArraySession;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeHttpTransport;
use Tests\Support\TestApplication;

final class FeedSynchronizationApiTest extends TestCase
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

    public function testCreationImportsArticlesAndRefreshDeduplicatesAndUsesConditionalHeaders(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, [
                'content-type' => 'application/rss+xml',
                'etag' => '"v1"',
                'last-modified' => 'Wed, 23 Sep 2026 12:00:00 GMT',
            ], $this->fixture('sync-rss.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, [
                'content-type' => 'application/rss+xml',
                'etag' => '"v2"',
            ], $this->fixture('sync-rss-updated.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(304, [], ''),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $createdResponse = $this->createFeed($kernel, $csrf, 'https://feeds.test/rss.xml', null);
        self::assertSame(201, $createdResponse->status);
        $created = $this->decode($createdResponse)['data'];
        self::assertSame('Journal sécurisé', $created['name']);
        self::assertSame('success', $created['last_fetch_status']);
        self::assertSame('2026-09-24T12:00:00Z', $created['last_article_at']);
        $feedId = $created['id'];
        self::assertIsInt($feedId);

        $articles = $this->articles($feedId);
        self::assertCount(2, $articles);
        self::assertSame('<p>Résumé <strong>utile</strong></p>', $articles[0]['summary']);
        self::assertStringNotContainsString('<script', (string) $articles[0]['summary']);
        self::assertSame('2026-09-24T12:00:00Z', $articles[0]['discovered_at']);

        $this->pdo->exec(
            'UPDATE articles SET is_read = 1, is_favorite = 1 WHERE feed_id = ' . $feedId . ' AND guid = \'article-1\''
        );
        $refreshed = $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));
        self::assertSame(200, $refreshed->status);
        $refreshData = $this->decode($refreshed)['data'];
        self::assertSame(1, $refreshData['imported_articles']);
        self::assertFalse($refreshData['not_modified']);
        self::assertSame('Journal sécurisé', $refreshData['feed']['name']);

        $afterRefresh = $this->articles($feedId);
        self::assertCount(3, $afterRefresh);
        $first = $this->articleByGuid($afterRefresh, 'article-1');
        self::assertSame(1, $first['is_read']);
        self::assertSame(1, $first['is_favorite']);
        self::assertSame('Premier article modifié', $first['title']);
        self::assertSame('2026-09-24T12:00:00Z', $first['discovered_at']);
        self::assertNotNull($first['image_metadata_checked_at']);
        self::assertSame('"v1"', $transport->requests[3]->headers['if-none-match']);
        self::assertSame(
            'Wed, 23 Sep 2026 12:00:00 GMT',
            $transport->requests[3]->headers['if-modified-since'],
        );

        $notModified = $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));
        self::assertSame(200, $notModified->status);
        self::assertTrue($this->decode($notModified)['data']['not_modified']);
        self::assertSame('"v2"', $transport->requests[5]->headers['if-none-match']);
    }

    public function testExplicitFeedImageRemainsEligibleForRetryAfterDownloadFailure(): void
    {
        $feed = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/"><channel>
  <title>Flux image</title><link>https://site.test/</link>
  <item><guid>image-1</guid><title>Article image</title><link>https://site.test/articles/image-1</link>
    <media:content url="https://site.test/image.png" medium="image" type="image/png"/>
  </item>
</channel></rss>
XML;
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feed),
            new TransportResponse(503, [], ''),
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feed),
            new TransportResponse(503, [], ''),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/images.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);

        $refreshed = $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));
        self::assertSame(200, $refreshed->status);
        self::assertCount(4, $transport->requests);
        self::assertSame('https://site.test/image.png', $transport->requests[1]->url);
        self::assertSame('https://site.test/image.png', $transport->requests[3]->url);
        self::assertNull($this->articles($feedId)[0]['image_metadata_checked_at']);
    }

    public function testInvalidInitialFeedCreatesNothing(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'text/html'], '<html><body>Pas un flux</body></html>'),
            new TransportResponse(200, ['content-type' => 'image/png'], $this->fixture('sync-rss.xml')),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $response = $this->createFeed($kernel, $csrf, 'https://feeds.test/invalid', null);

        self::assertSame(422, $response->status);
        self::assertSame('INVALID_FEED', $this->decode($response)['error']['code']);
        $wrongType = $this->createFeed($kernel, $csrf, 'https://feeds.test/wrong-type', null);
        self::assertSame(422, $wrongType->status);
        self::assertSame('INVALID_FEED', $this->decode($wrongType)['error']['code']);
        self::assertSame(0, $this->tableCount('feeds'));
        self::assertSame(0, $this->tableCount('articles'));
    }

    public function testFailedRefreshPreservesArticlesAndRecordsControlledStatus(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('sync-rss.xml')),
            new TransportResponse(503, [], ''),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        $feed = $this->decode($this->createFeed($kernel, $csrf, 'https://feeds.test/rss.xml', 'Personnalisé'))['data'];
        $feedId = $feed['id'];
        self::assertIsInt($feedId);

        $failed = $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));

        self::assertSame(502, $failed->status);
        self::assertSame('FEED_FETCH_FAILED', $this->decode($failed)['error']['code']);
        self::assertSame(2, $this->tableCount('articles'));
        $statement = $this->pdo->query('SELECT last_fetch_status, last_fetch_error FROM feeds');
        self::assertNotFalse($statement);
        $stored = $statement->fetch();
        self::assertIsArray($stored);
        self::assertSame('error', $stored['last_fetch_status']);
        self::assertSame('Le flux distant n’a pas pu être récupéré.', $stored['last_fetch_error']);
    }

    public function testRefreshEnforcesOwnershipAndActiveState(): void
    {
        $aliceTransport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('sync-rss.xml')),
        ]);
        [$aliceKernel, $aliceCsrf] = $this->authenticatedKernel(
            'alice',
            'correct horse battery staple',
            $aliceTransport,
        );
        $feed = $this->decode($this->createFeed(
            $aliceKernel,
            $aliceCsrf,
            'https://feeds.test/rss.xml',
            null,
        ))['data'];
        $feedId = $feed['id'];
        self::assertIsInt($feedId);

        [$bobKernel, $bobCsrf] = $this->authenticatedKernel(
            'bob',
            'another correct horse battery',
            new FakeHttpTransport([]),
        );
        self::assertSame(404, $bobKernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $bobCsrf],
        ))->status);

        self::assertSame(200, $aliceKernel->handle(new Request(
            'PATCH',
            '/api/feeds/' . $feedId,
            ['content-type' => 'application/json', 'x-csrf-token' => $aliceCsrf],
            '{"is_active":false}',
        ))->status);
        self::assertSame(409, $aliceKernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $aliceCsrf],
        ))->status);
    }

    public function testRefreshAllContinuesAfterOneFeedFails(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('sync-rss.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'application/atom+xml'], $this->fixture('sync-atom.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(500, [], ''),
            new TransportResponse(200, ['content-type' => 'application/atom+xml'], $this->fixture('sync-atom.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        self::assertSame(201, $this->createFeed($kernel, $csrf, 'https://feeds.test/rss.xml', 'A')->status);
        self::assertSame(201, $this->createFeed($kernel, $csrf, 'https://feeds.test/atom.xml', 'B')->status);

        $response = $kernel->handle(new Request(
            'POST',
            '/api/feeds/refresh',
            ['x-csrf-token' => $csrf],
        ));

        self::assertSame(200, $response->status);
        $results = $this->decode($response)['data']['results'];
        self::assertCount(2, $results);
        self::assertSame('error', $results[0]['status']);
        self::assertSame('success', $results[1]['status']);
    }

    public function testRepublishedItemWithNewGuidUpdatesTheExistingArticle(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('republish-rss.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('republish-rss-updated.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/republish.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);
        self::assertCount(1, $this->articles($feedId));
        $discoveredAt = $this->articleByGuid($this->articles($feedId), 'rep-a')['discovered_at'];

        $this->pdo->exec('UPDATE articles SET is_read = 1, is_favorite = 1 WHERE feed_id = ' . $feedId);
        $refreshed = $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));
        self::assertSame(200, $refreshed->status);

        $articles = $this->articles($feedId);
        self::assertCount(4, $articles);
        $republished = $this->articleByGuid($articles, 'rep-a');
        self::assertSame(1, $republished['is_read']);
        self::assertSame(1, $republished['is_favorite']);
        self::assertSame(
            'Version republished avec un nouveau GUID',
            trim(strip_tags((string) $republished['summary'])),
        );
        self::assertSame($discoveredAt, $republished['discovered_at']);
    }

    public function testDistinctArticlesSharingAGenericUrlAreNotMerged(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('republish-rss.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('republish-rss-updated.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/republish.xml', null);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);

        $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));

        $articles = $this->articles($feedId);
        self::assertCount(4, $articles);
        $rootArticles = array_values(array_filter(
            $articles,
            static fn(array $article): bool => $article['url'] === 'https://site.test/',
        ));
        self::assertCount(2, $rootArticles);
        self::assertSame(['Episode A', 'Episode B'], [
            $rootArticles[0]['title'],
            $rootArticles[1]['title'],
        ]);
    }

    public function testRebroadcastOfAnEarlierEpisodeIsKeptAsADistinctArticle(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('republish-rss.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('republish-rss-updated.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/republish.xml', null);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);

        $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));

        $articles = $this->articles($feedId);
        // Le rediffus du 29 septembre partage l'URL et le titre de l'article du
        // 28 septembre : seul le jour de publication les distingue, il doit donc
        // rester un article distinct.
        $rebroadcasts = array_values(array_filter(
            $articles,
            static fn(array $article): bool => $article['title'] === 'Le president resigne',
        ));
        self::assertCount(2, $rebroadcasts);
        self::assertSame(
            ['2026-09-28T08:30:00Z', '2026-09-29T08:00:00Z'],
            [$rebroadcasts[0]['published_at'], $rebroadcasts[1]['published_at']],
        );
        self::assertNotSame($rebroadcasts[0]['id'], $rebroadcasts[1]['id']);
    }

    /** @return array{ApiKernel, string} */
    private function authenticatedKernel(string $username, string $password, FakeHttpTransport $transport): array
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session, $this->client($transport));
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

    private function client(FakeHttpTransport $transport): SafeHttpClient
    {
        return new SafeHttpClient(
            new RemoteUrlGuard(new UrlNormalizer(), new FakeDnsResolver([
                'feeds.test' => ['93.184.216.34'],
                'site.test' => ['93.184.216.35'],
            ]), new IpAddressValidator()),
            $transport,
            new UrlResolver(),
            100,
            500,
            1_000_000,
            3,
            'RSSReader/Test',
        );
    }

    private function createFeed(ApiKernel $kernel, string $csrf, string $url, ?string $name): Response
    {
        $data = ['feed_url' => $url, 'category_id' => null];
        if ($name !== null) {
            $data['name'] = $name;
        }

        return $kernel->handle(new Request(
            'POST',
            '/api/feeds',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            json_encode($data, JSON_THROW_ON_ERROR),
        ));
    }

    /** @return list<array<string, mixed>> */
    private function articles(int $feedId): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM articles WHERE feed_id = :feed_id ORDER BY id');
        $statement->execute(['feed_id' => $feedId]);
        $articles = [];
        while (($article = $statement->fetch()) !== false) {
            if (is_array($article)) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    /** @param list<array<string, mixed>> $articles
     *  @return array<string, mixed>
     */
    private function articleByGuid(array $articles, string $guid): array
    {
        foreach ($articles as $article) {
            if ($article['guid'] === $guid) {
                return $article;
            }
        }
        self::fail('Article introuvable.');
    }

    private function tableCount(string $table): int
    {
        $statement = $this->pdo->query('SELECT COUNT(*) FROM ' . $table);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/Fixtures/Feeds/' . $name);
        self::assertIsString($content);

        return $content;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
