<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\SafeHttpClient;
use App\Http\TransportResponse;
use App\Http\UrlResolver;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Service\ArticleCoverDeduplicator;
use App\Service\RemoteMediaService;
use App\Validation\UrlNormalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeHttpTransport;
use Tests\Support\MemoryMediaStorage;

final class ArticleCoverDeduplicatorTest extends TestCase
{
    public function testImageSourcesAreExtractedFromStoredContent(): void
    {
        $deduplicator = $this->deduplicator([]);

        self::assertSame(
            ['https://site.test/a.png', 'https://site.test/b.png'],
            $deduplicator->imageSources(
                '<p>Début</p><img src="https://site.test/a.png">'
                    . '<img src="https://site.test/a.png"><img src="https://site.test/b.png">',
            ),
        );
        self::assertSame([], $deduplicator->imageSources('<p>Sans image.</p>'));
    }

    public function testStripSourcesRemovesOnlyMatchingImages(): void
    {
        $deduplicator = $this->deduplicator([]);
        $content = '<p>Début.</p><img src="https://site.test/couverture.png">'
            . '<p>Texte.</p><img src="https://site.test/capture.png">';

        $cleaned = $deduplicator->stripSources($content, ['https://site.test/couverture.png']);

        self::assertStringContainsString('https://site.test/capture.png', $cleaned);
        self::assertStringNotContainsString('couverture', $cleaned);
        self::assertStringContainsString('<p>Début.</p>', $cleaned);
        self::assertStringContainsString('<p>Texte.</p>', $cleaned);

        self::assertSame($content, $deduplicator->stripSources($content, ['https://site.test/absente.png']));
        self::assertSame($content, $deduplicator->stripSources($content, []));
    }

    public function testUrlMatchingAcceptsTheSameFileWithAnotherExtension(): void
    {
        $deduplicator = $this->deduplicator([]);

        self::assertSame(
            ['https://site.test/photos/visuel-1.jpg'],
            $deduplicator->sourcesMatchingUrl(
                'https://site.test/photos/visuel-1.png',
                ['https://site.test/photos/visuel-1.jpg'],
            ),
        );

        self::assertSame([], $deduplicator->sourcesMatchingUrl(
            'https://site.test/photos/visuel-1.png',
            ['https://site.test/photos/visuel-2.jpg'],
        ));

        self::assertSame([], $deduplicator->sourcesMatchingUrl(
            'https://site.test/pikapi/images/7b50cf5f/448',
            ['https://site.test/pikapi/images/7b50cf5f/2048'],
        ));
    }

    public function testMediaMatchingRequiresIdenticalBytesOrEquivalentArtwork(): void
    {
        $artwork = $this->gradientImage();
        $deduplicator = $this->deduplicator([
            new TransportResponse(200, ['content-type' => 'image/png'], $artwork['png']),
            new TransportResponse(200, ['content-type' => 'image/jpeg'], $artwork['jpeg']),
            new TransportResponse(200, ['content-type' => 'image/png'], $this->gradientImage(true)['png']),
            new TransportResponse(410, [], ''),
        ]);
        $cover = new \App\Model\StoredMedia($artwork['jpeg'], 'image/jpeg');

        $matches = $deduplicator->sourcesMatchingMedia(1, $cover, [
            'https://cdn.test/artwork.png',
            'https://cdn.test/artwork-bis.jpg',
            'https://cdn.test/inverse.png',
            'https://cdn.test/indisponible.png',
        ]);

        self::assertSame(['https://cdn.test/artwork.png', 'https://cdn.test/artwork-bis.jpg'], $matches);
    }

    public function testMediaMatchingSkipsUnreadableCover(): void
    {
        $deduplicator = $this->deduplicator([]);

        self::assertSame([], $deduplicator->sourcesMatchingMedia(
            1,
            null,
            ['https://cdn.test/artwork.png'],
        ));
    }

    /**
     * @param list<TransportResponse> $responses
     */
    private function deduplicator(array $responses): ArticleCoverDeduplicator
    {
        $http = new SafeHttpClient(
            new RemoteUrlGuard(new UrlNormalizer(), new FakeDnsResolver([
                'cdn.test' => ['93.184.216.34'],
                'site.test' => ['93.184.216.40'],
            ]), new IpAddressValidator()),
            new FakeHttpTransport($responses),
            new UrlResolver(),
            100,
            500,
            1_000_000,
            2,
            'RSSReader/Test',
        );
        $storage = new MemoryMediaStorage();

        return new ArticleCoverDeduplicator(
            new RemoteMediaService($http, $storage, 1_000_000, 4096, 4096, 16_777_216),
            $storage,
            new UrlNormalizer(),
        );
    }

    /** @return array{png: string, jpeg: string} */
    private function gradientImage(bool $inverse = false): array
    {
        $image = imagecreatetruecolor(64, 64);
        for ($x = 0; $x < 64; ++$x) {
            $level = (int) ($x / 64 * 255);
            if ($level < 0 || $level > 255) {
                $level = 0;
            }
            $color = imagecolorallocate(
                $image,
                $inverse ? 255 - $level : $level,
                $inverse ? 255 - $level : $level,
                $inverse ? 255 - $level : $level,
            );
            if (!is_int($color)) {
                $color = 0;
            }
            imageline($image, $x, 0, $x, 63, $color);
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        ob_start();
        imagejpeg($image, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return ['png' => $png, 'jpeg' => $jpeg];
    }
}
