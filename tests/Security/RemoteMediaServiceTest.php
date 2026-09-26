<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Exception\InvalidMediaException;
use App\Exception\RemoteHttpException;
use App\Http\SafeHttpClient;
use App\Http\TransportResponse;
use App\Http\UrlResolver;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Service\RemoteMediaService;
use App\Validation\UrlNormalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeHttpTransport;
use Tests\Support\MemoryMediaStorage;

final class RemoteMediaServiceTest extends TestCase
{
    public function testValidatedImageIsStoredUnderAnApplicationGeneratedKey(): void
    {
        $storage = new MemoryMediaStorage();
        $service = $this->service([
            new TransportResponse(200, ['content-type' => 'image/png; charset=binary'], $this->png()),
        ], $storage);

        $key = $service->download(7, 'https://cdn.test/untrusted-name.svg');

        self::assertStringStartsWith('u7/', $key);
        self::assertStringEndsWith('.png', $key);
        self::assertSame($this->png(), $storage->read(7, $key)?->content);
        self::assertNull($storage->read(8, $key));
    }

    public function testFakeMimeAndSvgAreRejected(): void
    {
        $storage = new MemoryMediaStorage();
        $fakeMime = $this->service([
            new TransportResponse(200, ['content-type' => 'image/jpeg'], $this->png()),
        ], $storage);
        try {
            $fakeMime->download(1, 'https://cdn.test/image');
            self::fail('Le faux type MIME aurait dû être rejeté.');
        } catch (InvalidMediaException) {
            self::assertSame([], $storage->media);
        }

        $svg = $this->service([
            new TransportResponse(200, ['content-type' => 'image/svg+xml'], '<svg xmlns="http://www.w3.org/2000/svg"/>'),
        ], $storage);
        $this->expectException(InvalidMediaException::class);
        $svg->download(1, 'https://cdn.test/image.svg');
    }

    public function testResponseLimitIsAppliedBeforeImageInspection(): void
    {
        $service = $this->service([
            new TransportResponse(200, ['content-type' => 'image/png'], str_repeat('x', 2049)),
        ], new MemoryMediaStorage(), 2048);

        $this->expectException(RemoteHttpException::class);
        $service->download(1, 'https://cdn.test/too-large.png');
    }

    public function testDimensionLimitIsEnforced(): void
    {
        $widePng = $this->png();
        $widePng[19] = "\x02";
        $storage = new MemoryMediaStorage();
        $http = new SafeHttpClient(
            new RemoteUrlGuard(new UrlNormalizer(), new FakeDnsResolver([
                'cdn.test' => ['93.184.216.34'],
            ]), new IpAddressValidator()),
            new FakeHttpTransport([
                new TransportResponse(200, ['content-type' => 'image/png'], $widePng),
            ]),
            new UrlResolver(),
            100,
            500,
            100_000,
            2,
            'RSSReader/Test',
        );
        $service = new RemoteMediaService($http, $storage, 100_000, 1, 4096, 4096);

        $this->expectException(InvalidMediaException::class);
        $service->download(1, 'https://cdn.test/wide.png');
    }

    /** @param list<TransportResponse> $responses */
    private function service(
        array $responses,
        MemoryMediaStorage $storage,
        int $maxBytes = 100_000,
    ): RemoteMediaService {
        $http = new SafeHttpClient(
            new RemoteUrlGuard(new UrlNormalizer(), new FakeDnsResolver([
                'cdn.test' => ['93.184.216.34'],
            ]), new IpAddressValidator()),
            new FakeHttpTransport($responses),
            new UrlResolver(),
            100,
            500,
            $maxBytes,
            2,
            'RSSReader/Test',
        );

        return new RemoteMediaService($http, $storage, $maxBytes, 4096, 4096, 16_777_216);
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
}
