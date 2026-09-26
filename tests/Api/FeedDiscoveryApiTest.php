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

final class FeedDiscoveryApiTest extends TestCase
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

    public function testAuthenticationAndCsrfAreRequired(): void
    {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session, $this->httpClient([]));
        $csrf = $this->csrf($kernel);

        self::assertSame(401, $this->discover($kernel, $csrf, 'https://site.test/')->status);

        [$authenticated] = $this->authenticatedKernel($this->httpClient([]));
        self::assertSame(403, $authenticated->handle(new Request(
            'POST',
            '/api/feed-discovery',
            ['content-type' => 'application/json'],
            '{"url":"https://site.test/"}',
        ))->status);
    }

    public function testDirectRssAndAtomDocumentsAreDetected(): void
    {
        foreach ([
            ['rss.xml', 'application/rss+xml; charset=utf-8', 'rss', 'Actualités RSS'],
            ['atom.xml', 'application/atom+xml', 'atom', 'Actualités Atom'],
        ] as [$fixture, $contentType, $type, $title]) {
            $transport = new FakeHttpTransport([
                new TransportResponse(200, ['content-type' => $contentType], $this->fixture($fixture)),
            ]);
            [$kernel, $csrf] = $this->authenticatedKernel($this->httpClient([], $transport));
            $response = $this->discover($kernel, $csrf, 'https://feeds.test/' . $fixture);

            self::assertSame(200, $response->status);
            $data = $this->decode($response)['data'];
            self::assertSame('https://feeds.test/' . $fixture, $data['site_url']);
            self::assertSame($type, $data['feeds'][0]['type']);
            self::assertSame($title, $data['feeds'][0]['title']);
        }
    }

    public function testRelativeFeedUrlIsResolvedAndNormalized(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'text/html'], $this->fixture('one-relative.html')),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel($this->httpClient([], $transport));

        $response = $this->discover($kernel, $csrf, 'https://site.test/news/index.html');

        self::assertSame(200, $response->status);
        $data = $this->decode($response)['data'];
        self::assertSame('https://site.test/news/index.html', $data['site_url']);
        self::assertSame([[
            'title' => 'Flux principal',
            'url' => 'https://site.test/feeds/main.xml',
            'type' => 'rss',
        ]], $data['feeds']);
    }

    public function testMultipleChoicesAreReturnedWithoutDuplicateOrUnsafeLinks(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'text/html'], $this->fixture('multiple.html')),
        ]);
        [$kernel, $csrf] = $this->authenticatedKernel($this->httpClient([], $transport));

        $response = $this->discover($kernel, $csrf, 'https://site.test/');

        self::assertSame(200, $response->status);
        $feeds = $this->decode($response)['data']['feeds'];
        self::assertSame([
            ['title' => 'RSS', 'url' => 'https://feeds.test/public/rss.xml', 'type' => 'rss'],
            ['title' => 'Atom', 'url' => 'https://feeds.test/public/atom.xml', 'type' => 'atom'],
        ], $feeds);
    }

    public function testMissingFeedDangerousXmlAndUnsupportedContentAreRejected(): void
    {
        $responses = [
            new TransportResponse(200, ['content-type' => 'text/html'], $this->fixture('none.html')),
            new TransportResponse(
                200,
                ['content-type' => 'application/xml'],
                '<!DOCTYPE rss [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>'
                    . '<rss version="2.0"><channel><title>&xxe;</title></channel></rss>',
            ),
            new TransportResponse(200, ['content-type' => 'image/png'], "\x89PNG"),
        ];
        [$kernel, $csrf] = $this->authenticatedKernel($this->httpClient([], new FakeHttpTransport($responses)));

        $none = $this->discover($kernel, $csrf, 'https://site.test/none');
        self::assertSame(422, $none->status);
        self::assertSame('NO_FEED_FOUND', $this->decode($none)['error']['code']);

        $xxe = $this->discover($kernel, $csrf, 'https://site.test/xxe');
        self::assertSame(422, $xxe->status);
        self::assertSame('UNSUPPORTED_REMOTE_CONTENT', $this->decode($xxe)['error']['code']);

        $image = $this->discover($kernel, $csrf, 'https://site.test/image');
        self::assertSame(422, $image->status);
        self::assertSame('UNSUPPORTED_REMOTE_CONTENT', $this->decode($image)['error']['code']);
    }

    public function testUnsafeInitialUrlAndRemoteFailureReturnControlledErrors(): void
    {
        [$kernel, $csrf] = $this->authenticatedKernel($this->httpClient([]));
        $unsafe = $this->discover($kernel, $csrf, 'http://127.0.0.1/private');
        self::assertSame(422, $unsafe->status);
        self::assertSame('VALIDATION_ERROR', $this->decode($unsafe)['error']['code']);

        $transport = new FakeHttpTransport([new TransportResponse(503, [], '')]);
        [$otherKernel, $otherCsrf] = $this->authenticatedKernel($this->httpClient([], $transport));
        $failure = $this->discover($otherKernel, $otherCsrf, 'https://site.test/failure');
        self::assertSame(502, $failure->status);
        self::assertSame('REMOTE_FETCH_FAILED', $this->decode($failure)['error']['code']);
    }

    public function testDiscoveryIsRateLimitedPerUser(): void
    {
        $responses = array_fill(
            0,
            10,
            new TransportResponse(200, ['content-type' => 'text/html'], $this->fixture('none.html')),
        );
        [$kernel, $csrf] = $this->authenticatedKernel(
            $this->httpClient([], new FakeHttpTransport($responses)),
        );
        for ($attempt = 0; $attempt < 10; ++$attempt) {
            self::assertSame(422, $this->discover($kernel, $csrf, 'https://site.test/')->status);
        }

        $limited = $this->discover($kernel, $csrf, 'https://site.test/');
        self::assertSame(429, $limited->status);
        self::assertSame('TOO_MANY_ATTEMPTS', $this->decode($limited)['error']['code']);

        [$bobKernel, $bobCsrf] = $this->authenticatedKernel(
            $this->httpClient([], new FakeHttpTransport([
                new TransportResponse(200, ['content-type' => 'text/html'], $this->fixture('none.html')),
            ])),
            'bob',
            'another correct horse battery',
        );
        self::assertSame(422, $this->discover($bobKernel, $bobCsrf, 'https://site.test/')->status);
    }

    /** @param array<string, list<string>> $extraAnswers */
    private function httpClient(
        array $extraAnswers,
        ?FakeHttpTransport $transport = null,
    ): SafeHttpClient {
        return new SafeHttpClient(
            new RemoteUrlGuard(
                new UrlNormalizer(),
                new FakeDnsResolver($extraAnswers + [
                    'site.test' => ['93.184.216.34'],
                    'feeds.test' => ['93.184.216.35'],
                ]),
                new IpAddressValidator(),
            ),
            $transport ?? new FakeHttpTransport([]),
            new UrlResolver(),
            100,
            500,
            1_000_000,
            3,
            'RSSReader/Test',
        );
    }

    /** @return array{ApiKernel, string} */
    private function authenticatedKernel(
        SafeHttpClient $httpClient,
        string $username = 'alice',
        string $password = 'correct horse battery staple',
    ): array {
        $session = new ArraySession();
        $kernel = $this->application->kernel($session, $httpClient);
        $csrf = $this->csrf($kernel);
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

    private function csrf(ApiKernel $kernel): string
    {
        $csrf = $this->decode($kernel->handle(new Request('GET', '/api/auth/csrf')))['data']['csrf_token'];
        self::assertIsString($csrf);

        return $csrf;
    }

    private function discover(ApiKernel $kernel, string $csrf, string $url): Response
    {
        return $kernel->handle(new Request(
            'POST',
            '/api/feed-discovery',
            ['content-type' => 'application/json', 'x-csrf-token' => $csrf],
            json_encode(['url' => $url], JSON_THROW_ON_ERROR),
        ));
    }

    private function fixture(string $name): string
    {
        $contents = file_get_contents(dirname(__DIR__) . '/Fixtures/Feeds/' . $name);
        self::assertIsString($contents);

        return $contents;
    }

    /** @return array<string, mixed> */
    private function decode(Response $response): array
    {
        $decoded = json_decode($response->body, true, 32, JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);

        return $decoded;
    }
}
