<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Exception\RemoteHttpException;
use App\Http\SafeHttpClient;
use App\Http\TransportResponse;
use App\Http\UrlResolver;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Validation\UrlNormalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeHttpTransport;

final class SafeHttpClientTest extends TestCase
{
    public function testSuccessfulRequestPinsValidatedAddressAndAppliesLimits(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, ['content-type' => 'application/rss+xml'], '<rss/>'),
        ]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']]);

        $response = $client->get('https://example.org/feed', [
            'Accept' => 'application/rss+xml',
            'If-None-Match' => '"version-1"',
        ], 1024);

        self::assertSame(200, $response->status);
        self::assertSame('<rss/>', $response->body);
        self::assertSame('https://example.org/feed', $response->finalUrl);
        self::assertCount(1, $transport->requests);
        $request = $transport->requests[0];
        self::assertSame('93.184.216.34', $request->address);
        self::assertSame('example.org', $request->host);
        self::assertSame(443, $request->port);
        self::assertSame(3000, $request->connectTimeoutMs);
        self::assertSame(10000, $request->timeoutMs);
        self::assertSame(1024, $request->maxResponseBytes);
        self::assertSame('RSSReader/Test', $request->userAgent);
        self::assertSame('application/rss+xml', $request->headers['accept']);
    }

    public function testRelativeRedirectIsResolvedAndRevalidated(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(302, ['location' => '../rss.xml'], ''),
            new TransportResponse(200, ['content-type' => 'application/atom+xml'], '<feed/>'),
        ]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']]);

        $response = $client->get('https://example.org/path/start');

        self::assertSame('https://example.org/rss.xml', $response->finalUrl);
        self::assertCount(2, $transport->requests);
        self::assertSame('https://example.org/rss.xml', $transport->requests[1]->url);
    }

    public function testARequestCanUseAScopedUserAgentAcrossRedirects(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(302, ['location' => '/article/final'], ''),
            new TransportResponse(200, ['content-type' => 'text/html'], '<html></html>'),
        ]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']]);

        $client->get(
            'https://example.org/article',
            ['accept' => 'text/html'],
            2048,
            'Mozilla/5.0 RSSReader/Page-Test',
        );

        self::assertCount(2, $transport->requests);
        self::assertSame('Mozilla/5.0 RSSReader/Page-Test', $transport->requests[0]->userAgent);
        self::assertSame('Mozilla/5.0 RSSReader/Page-Test', $transport->requests[1]->userAgent);
    }

    public function testPublicRedirectToPrivateDestinationIsBlockedBeforeSecondRequest(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(302, ['location' => 'http://internal.example/admin'], ''),
        ]);
        $client = $this->client($transport, [
            'public.example' => ['93.184.216.34'],
            'internal.example' => ['10.0.0.5'],
        ]);

        $exception = $this->captureException(
            static fn() => $client->get('https://public.example/feed'),
        );
        self::assertSame('UNSAFE_ADDRESS', $exception->reason);
        self::assertCount(1, $transport->requests);
    }

    public function testRedirectLoopIsRejected(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(302, ['location' => '/feed'], ''),
        ]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']]);

        $exception = $this->captureException(
            static fn() => $client->get('https://example.org/feed'),
        );
        self::assertSame('REDIRECT_LOOP', $exception->reason);
        self::assertCount(1, $transport->requests);
    }

    public function testRedirectLimitIsEnforced(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(302, ['location' => '/second'], ''),
            new TransportResponse(302, ['location' => '/third'], ''),
        ]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']], 1);

        $exception = $this->captureException(
            static fn() => $client->get('https://example.org/first'),
        );
        self::assertSame('TOO_MANY_REDIRECTS', $exception->reason);
        self::assertCount(2, $transport->requests);
    }

    public function testOversizedResponseIsRejectedEvenIfTransportMisbehaves(): void
    {
        $transport = new FakeHttpTransport([
            new TransportResponse(200, [], str_repeat('x', 1025)),
        ]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']]);

        $exception = $this->captureException(
            static fn() => $client->get('https://example.org/feed', maxResponseBytes: 1024),
        );
        self::assertSame('RESPONSE_TOO_LARGE', $exception->reason);
    }

    public function testUnsafeOutgoingHeadersAreRejected(): void
    {
        $transport = new FakeHttpTransport([]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']]);

        $hostHeader = $this->captureException(
            static fn() => $client->get('https://example.org/feed', ['Host' => 'internal']),
        );
        self::assertSame('INVALID_HEADER', $hostHeader->reason);

        $injection = $this->captureException(
            static fn() => $client->get('https://example.org/feed', ['Accept' => "text/xml\r\nX-Evil: yes"]),
        );
        self::assertSame('INVALID_HEADER', $injection->reason);
        self::assertSame([], $transport->requests);
    }

    public function testUnsafeUserAgentIsRejected(): void
    {
        $transport = new FakeHttpTransport([]);
        $client = $this->client($transport, ['example.org' => ['93.184.216.34']]);

        $exception = $this->captureException(
            static fn() => $client->get('https://example.org/', userAgent: "Browser\r\nX-Evil: yes"),
        );

        self::assertSame('INVALID_USER_AGENT', $exception->reason);
        self::assertSame([], $transport->requests);
    }

    /** @param array<string, list<string>> $answers */
    private function client(
        FakeHttpTransport $transport,
        array $answers,
        int $maxRedirects = 5,
    ): SafeHttpClient {
        return new SafeHttpClient(
            new RemoteUrlGuard(
                new UrlNormalizer(),
                new FakeDnsResolver($answers),
                new IpAddressValidator(),
            ),
            $transport,
            new UrlResolver(),
            3000,
            10000,
            4096,
            $maxRedirects,
            'RSSReader/Test',
        );
    }

    /** @param callable(): mixed $operation */
    private function captureException(callable $operation): RemoteHttpException
    {
        try {
            $operation();
            self::fail('Une exception RemoteHttpException était attendue.');
        } catch (RemoteHttpException $exception) {
            return $exception;
        }
    }
}
