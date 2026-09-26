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

final class MediaApiTest extends TestCase
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

    public function testMediaIsDownloadedServedWithOwnershipAndDeletedWithFeed(): void
    {
        $png = $this->png();
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $this->fixture('media-rss.xml')),
            new TransportResponse(200, ['content-type' => 'image/png'], $png),
            new TransportResponse(200, ['content-type' => 'image/png'], $png),
            new TransportResponse(200, ['content-type' => 'image/jpeg'], $png),
        ]);
        [$alice, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);

        $createdResponse = $alice->handle(new Request(
            'POST',
            '/api/feeds',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"feed_url":"https://feeds.test/media.xml","category_id":null}',
        ));
        self::assertSame(201, $createdResponse->status);
        $created = $this->decode($createdResponse)['data'];
        $feedId = $created['id'];
        self::assertIsInt($feedId);
        self::assertSame('/api/feeds/' . $feedId . '/favicon', $created['favicon_url']);
        self::assertArrayNotHasKey('favicon_path', $created);

        $statement = $this->pdo->query(
            'SELECT id, image_path FROM articles WHERE image_path IS NOT NULL ORDER BY id'
        );
        self::assertNotFalse($statement);
        $article = $statement->fetch();
        self::assertIsArray($article);
        $articleId = (int) $article['id'];
        self::assertIsString($article['image_path']);
        self::assertCount(2, $this->application->mediaStorage->media);

        $favicon = $alice->handle(new Request('GET', '/api/feeds/' . $feedId . '/favicon'));
        self::assertSame(200, $favicon->status);
        self::assertSame('image/png', $favicon->headers['Content-Type']);
        self::assertSame($png, $favicon->body);

        $image = $alice->handle(new Request('GET', '/api/articles/' . $articleId . '/image'));
        self::assertSame(200, $image->status);
        self::assertSame('image/png', $image->headers['Content-Type']);

        [$bob] = $this->authenticatedKernel(
            'bob',
            'another correct horse battery',
            new FakeHttpTransport([]),
        );
        self::assertSame(404, $bob->handle(new Request('GET', '/api/feeds/' . $feedId . '/favicon'))->status);
        self::assertSame(404, $bob->handle(new Request('GET', '/api/articles/' . $articleId . '/image'))->status);

        self::assertSame(204, $alice->handle(new Request(
            'DELETE',
            '/api/feeds/' . $feedId,
            ['x-csrf-token' => $csrf],
        ))->status);
        self::assertSame([], $this->application->mediaStorage->media);
    }

    public function testMissingImageIsRetriedForAnExistingArticle(): void
    {
        $feed = '<?xml version="1.0"?><rss version="2.0" '
            . 'xmlns:media="http://search.yahoo.com/mrss/"><channel><title>Retry</title>'
            . '<item><guid>retry-1</guid><title>Retry image</title>'
            . '<media:content url="https://cdn.test/retry.png" type="image/png"/>'
            . '</item></channel></rss>';
        $png = $this->png();
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feed),
            new TransportResponse(200, ['content-type' => 'image/jpeg'], $png),
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], $feed),
            new TransportResponse(200, ['content-type' => 'image/png'], $png),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel('alice', 'correct horse battery staple', $transport);
        $created = $this->decode($kernel->handle(new Request(
            'POST',
            '/api/feeds',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            '{"feed_url":"https://feeds.test/retry.xml","category_id":null}',
        )))['data'];
        $feedId = $created['id'];
        self::assertIsInt($feedId);
        $before = $this->pdo->query('SELECT image_path FROM articles');
        self::assertNotFalse($before);
        self::assertNull($before->fetchColumn() ?: null);

        $refreshed = $kernel->handle(new Request(
            'POST',
            '/api/feeds/' . $feedId . '/refresh',
            ['x-csrf-token' => $csrf],
        ));

        self::assertSame(200, $refreshed->status);
        self::assertSame(0, $this->decode($refreshed)['data']['imported_articles']);
        $after = $this->pdo->query('SELECT image_path FROM articles');
        self::assertNotFalse($after);
        self::assertIsString($after->fetchColumn());
        self::assertCount(1, $this->application->mediaStorage->media);
    }

    /** @return array{ApiKernel, string} */
    private function authenticatedKernel(
        string $username,
        string $password,
        FakeHttpTransport $transport,
    ): array {
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
                'cdn.test' => ['93.184.216.36'],
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

    private function fixture(string $name): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/Fixtures/Feeds/' . $name);
        self::assertIsString($content);

        return $content;
    }

    private function png(): string
    {
        $image = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($image);

        return $image;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
