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
use App\Service\FeedRefreshLock;
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

    public function testRefreshOfAFeedAlreadyBeingRefreshedIsRefused(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, [
                'content-type' => 'application/rss+xml',
                'etag' => '"v1"',
            ], $this->fixture('sync-rss.xml')),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
            new TransportResponse(200, [
                'content-type' => 'application/rss+xml',
                'etag' => '"v2"',
            ], $this->fixture('sync-rss-updated.xml')),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/rss.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);
        $attemptedBefore = $this->feedColumn($feedId, 'last_fetch_attempt_at');

        // Un second appel de l'API pendant une synchronisation en cours, ou le
        // cron pendant une actualisation manuelle : le flux est refuse, aucune
        // requete distante n'est faite et le validateur HTTP de la
        // synchronisation en cours n'est pas ecrase.
        $locks = new FeedRefreshLock($this->application->lockDirectory);
        $requestsBefore = count($transport->requests);
        self::assertTrue($locks->acquire($feedId));
        try {
            $busy = $kernel->handle(new Request(
                'POST',
                '/api/feeds/' . $feedId . '/refresh',
                ['x-csrf-token' => $csrf],
            ));
            self::assertSame(409, $busy->status);
            self::assertSame('FEED_BUSY', $this->decode($busy)['error']['code']);
        } finally {
            $locks->release($feedId);
        }

        self::assertCount(
            $requestsBefore,
            $transport->requests,
            'Un flux occupe ne declenche aucune requete distante',
        );
        self::assertSame($attemptedBefore, $this->feedColumn($feedId, 'last_fetch_attempt_at'));
        self::assertSame('"v1"', $this->feedColumn($feedId, 'etag'));

        $released = $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));
        self::assertSame(200, $released->status);
        self::assertSame('"v2"', $this->feedColumn($feedId, 'etag'));
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
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
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
        self::assertCount(5, $transport->requests);
        self::assertSame('https://site.test/image.png', $transport->requests[1]->url);
        self::assertSame('https://site.test/image.png', $transport->requests[4]->url);
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

    public function testMissingFeedContentIsExtractedFromThePublicArticlePageAndPreserved(): void
    {
        $feedWithoutContent = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0"><channel><title>Flux public</title><link>https://site.test/</link>
  <item><guid>public-1</guid><title>Article public</title>
    <link>https://site.test/articles/public-1</link><description>Résumé du flux.</description>
  </item>
</channel></rss>
XML;
        $feedWithContent = <<<'XML'
<?xml version="1.0"?>
<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/"><channel>
  <title>Flux public</title><link>https://site.test/</link>
  <item><guid>public-1</guid><title>Article public</title>
    <link>https://site.test/articles/public-1</link><description>Résumé du flux.</description>
    <content:encoded><![CDATA[<p>CONTENU_FLUX_COMPLET %s</p>]]></content:encoded>
  </item>
</channel></rss>
XML;
        $feedWithContent = sprintf($feedWithContent, str_repeat('texte du flux ', 20));
        $pageText = 'Coup de tonnerre dans les réseaux poitevins de solidarité. '
            . str_repeat('Contenu public extrait. ', 12);
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feedWithoutContent),
            new TransportResponse(
                200,
                ['content-type' => 'text/html'],
                '<article><div data-testid="contenu-article"><p>' . $pageText . '</p></div></article>'
            ),
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feedWithoutContent),
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feedWithContent),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/public.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);
        $article = $this->articles($feedId)[0];
        self::assertSame('page', $article['content_source']);
        self::assertNotNull($article['content_page_checked_at']);
        self::assertStringContainsString('Coup de tonnerre', (string) $article['content']);
        self::assertSame(1, $this->tableCount('articles_fts'));
        self::assertSame('Mozilla/5.0 RSSReader/Page-Test', $transport->requests[1]->userAgent);

        self::assertSame(200, $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ))->status);
        $preserved = $this->articles($feedId)[0];
        self::assertSame('page', $preserved['content_source']);
        self::assertStringContainsString('Coup de tonnerre', (string) $preserved['content']);

        self::assertSame(200, $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ))->status);
        $replaced = $this->articles($feedId)[0];
        self::assertSame('feed', $replaced['content_source']);
        self::assertStringContainsString('CONTENU_FLUX_COMPLET', (string) $replaced['content']);
        self::assertCount(4, $transport->requests, 'La page ne doit être téléchargée qu’une fois.');
    }

    public function testPageContentDoesNotRepeatTheChosenCover(): void
    {
        $pageText = 'Sans visuel dans le flux, l’illustration est prise dans la page. '
            . str_repeat('Texte public. ', 20);
        $feed = '<?xml version="1.0"?><rss version="2.0"><channel><title>Couverture</title>'
            . '<link>https://site.test/</link>'
            . '<item><guid>cover-1</guid><title>Article avec couverture</title>'
            . '<link>https://site.test/articles/cover-1</link><description>Résumé.</description></item>'
            . '</channel></rss>';
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feed),
            new TransportResponse(
                200,
                ['content-type' => 'text/html'],
                '<html><head><meta property="og:image" content="/photos/visuel-1.png"></head><body>'
                    . '<article><img src="/photos/visuel-1.png"><img src="/photos/annexe-1.png">'
                    . '<p>' . $pageText . '</p></article></body></html>',
            ),
            new TransportResponse(200, ['content-type' => 'image/png'], $this->png(320, 240)),
            new TransportResponse(200, ['content-type' => 'image/png'], $this->distinctPng(480, 320)),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/cover.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);

        $article = $this->articles($feedId)[0];
        self::assertIsString($article['image_path']);
        self::assertSame('page', $article['content_source']);
        self::assertStringContainsString(
            'https://site.test/photos/annexe-1.png',
            (string) $article['content'],
        );
        self::assertStringNotContainsString(
            'https://site.test/photos/visuel-1.png',
            (string) $article['content'],
        );
    }

    /**
     * Les gabarits publient la même illustration sous plusieurs résolutions
     * dont l'URL diffère (redimensionneur de type /resizer/taille/…) : la
     * couverture retenue depuis og:image et l'image du contenu reproduisent
     * alors le même visuel. La comparaison perceptuelle, au prix du
     * téléchargement de contrôle de la candidate, retire la répétition dès
     * la synchronisation.
     */
    public function testPageContentDoesNotRepeatACoverPublishedUnderAnotherResolution(): void
    {
        $pageText = 'L’illustration est servie dans deux résolutions par le même site. '
            . str_repeat('Texte public. ', 20);
        $feed = '<?xml version="1.0"?><rss version="2.0"><channel><title>Couverture</title>'
            . '<link>https://site.test/</link>'
            . '<item><guid>cover-2</guid><title>Article à deux résolutions</title>'
            . '<link>https://site.test/articles/cover-2</link><description>Résumé.</description></item>'
            . '</channel></rss>';
        $artwork = $this->png(320, 240);
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feed),
            new TransportResponse(
                200,
                ['content-type' => 'text/html'],
                '<html><head><meta property="og:image" content="/resizer/1600x900/photos/visuel-2.png"></head><body>'
                    . '<article><img src="/resizer/932x582/photos/visuel-2.png"><img src="/photos/annexe-2.png">'
                    . '<p>' . $pageText . '</p></article></body></html>',
            ),
            new TransportResponse(200, ['content-type' => 'image/png'], $artwork),
            new TransportResponse(200, ['content-type' => 'image/png'], $artwork),
            new TransportResponse(200, ['content-type' => 'image/png'], $this->distinctPng(640, 480)),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/cover2.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);

        $article = $this->articles($feedId)[0];
        self::assertIsString($article['image_path']);
        self::assertStringContainsString(
            'https://site.test/photos/annexe-2.png',
            (string) $article['content'],
        );
        self::assertStringNotContainsString(
            '/resizer/932x582/photos/visuel-2.png',
            (string) $article['content'],
        );
    }

    public function testFeedCoverIsNotRepeatedInsideItsOwnContent(): void
    {
        $feed = '<?xml version="1.0"?><rss version="2.0" '
            . 'xmlns:content="http://purl.org/rss/1.0/modules/content/" '
            . 'xmlns:media="http://search.yahoo.com/mrss/"><channel>'
            . '<title>Titre du flux</title><link>https://site.test/</link>'
            . '<item><guid>repetition-1</guid><title>Article doublé</title>'
            . '<link>https://site.test/articles/repetition-1</link>'
            . '<media:content url="https://site.test/photos/couverture.png" medium="image"/>'
            . '<content:encoded><![CDATA[<p>%s</p>'
            . '<img src="https://site.test/photos/couverture.png"><img src="https://site.test/photos/annexe-1.png">]]></content:encoded>'
            . '</item></channel></rss>';
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], sprintf($feed, str_repeat('Texte du flux. ', 20))),
            new TransportResponse(200, ['content-type' => 'image/png'], $this->png(320, 240)),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/repetition.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);

        $article = $this->articles($feedId)[0];
        self::assertIsString($article['image_path']);
        self::assertSame('feed', $article['content_source']);
        self::assertStringContainsString('Texte du flux.', (string) $article['content']);
        self::assertStringContainsString(
            'https://site.test/photos/annexe-1.png',
            (string) $article['content'],
        );
        self::assertStringNotContainsString(
            'https://site.test/photos/couverture.png',
            (string) $article['content'],
        );
    }

    /**
     * Le résumé des flux SPIP embarque la vignette déclarée en
     * media:content : la répétition est retirée du résumé comme du contenu,
     * la couverture restant la seule occurrence du visuel.
     */
    public function testFeedCoverIsNotRepeatedInsideItsOwnSummary(): void
    {
        $feed = '<?xml version="1.0"?><rss version="2.0" '
            . 'xmlns:media="http://search.yahoo.com/mrss/"><channel>'
            . '<title>Titre du flux</title><link>https://site.test/</link>'
            . '<item><guid>summary-dup-1</guid><title>Résumé doublé</title>'
            . '<link>https://site.test/articles/summary-dup-1</link>'
            . '<media:content url="https://site.test/photos/couverture.png" medium="image"/>'
            . '<description><![CDATA[<p>%s</p>'
            . '<img src="https://site.test/photos/couverture.png"><img src="https://site.test/photos/annexe-2.png">]]></description>'
            . '</item></channel></rss>';
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], sprintf($feed, str_repeat('Résumé du flux. ', 20))),
            new TransportResponse(200, ['content-type' => 'image/png'], $this->png(320, 240)),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $created = $this->createFeed($kernel, $csrf, 'https://feeds.test/summary-dup.xml', null);
        self::assertSame(201, $created->status);
        $feedId = $this->decode($created)['data']['id'];
        self::assertIsInt($feedId);

        $article = $this->articles($feedId)[0];
        self::assertIsString($article['image_path']);
        self::assertStringContainsString('Résumé du flux.', (string) $article['summary']);
        self::assertStringContainsString(
            'https://site.test/photos/annexe-2.png',
            (string) $article['summary'],
        );
        self::assertStringNotContainsString(
            'https://site.test/photos/couverture.png',
            (string) $article['summary'],
        );
    }

    private function png(int $width = 1, int $height = 1): string
    {
        if ($width === 1 && $height === 1) {
            $image = base64_decode(
                'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
                true,
            );
            self::assertIsString($image);

            return $image;
        }

        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        self::assertNotFalse($canvas);
        ob_start();
        imagepng($canvas);
        $encoded = ob_get_clean();
        imagedestroy($canvas);
        self::assertIsString($encoded);

        return $encoded;
    }

    /**
     * Un visuel de test réellement distinct : les toiles unies de png()
     * produisent des grilles perceptuelles identiques et seraient
     * dédoublonnées à raison.
     */
    private function distinctPng(int $width, int $height): string
    {
        $canvas = imagecreatetruecolor(max(1, $width), max(1, $height));
        self::assertNotFalse($canvas);
        $background = imagecolorallocate($canvas, 240, 240, 240);
        $mark = imagecolorallocate($canvas, 200, 30, 30);
        self::assertNotFalse($background);
        self::assertNotFalse($mark);
        imagefill($canvas, 0, 0, $background);
        imagefilledrectangle($canvas, 0, 0, $width - 1, (int) ($height / 2), $mark);
        ob_start();
        imagepng($canvas);
        $encoded = ob_get_clean();
        imagedestroy($canvas);
        self::assertIsString($encoded);

        return $encoded;
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

    private function feedColumn(int $feedId, string $column): mixed
    {
        $statement = $this->pdo->prepare(sprintf('SELECT %s FROM feeds WHERE id = :id', $column));
        $statement->execute(['id' => $feedId]);
        $value = $statement->fetchColumn();
        self::assertNotFalse($value, sprintf('La colonne %s doit exister.', $column));

        return $value;
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
