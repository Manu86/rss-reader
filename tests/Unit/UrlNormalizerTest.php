<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Exception\ValidationException;
use App\Validation\UrlNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlNormalizerTest extends TestCase
{
    private UrlNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new UrlNormalizer();
    }

    public function testItNormalizesSchemeHostDefaultPortEmptyPathAndFragment(): void
    {
        self::assertSame(
            'https://example.org/feed.xml?format=rss',
            $this->normalizer->normalizeHttpUrl(
                'HTTPS://Example.Org:443/feed.xml?format=rss#section',
            ),
        );
        self::assertSame(
            'http://example.org/',
            $this->normalizer->normalizeHttpUrl('http://example.org'),
        );
    }

    #[DataProvider('invalidUrls')]
    public function testItRejectsInvalidOrUnsafeUrlSyntax(string $url): void
    {
        $this->expectException(ValidationException::class);
        $this->normalizer->normalizeHttpUrl($url);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidUrls(): iterable
    {
        yield 'relative' => ['/feed.xml'];
        yield 'unsupported scheme' => ['file:///etc/passwd'];
        yield 'credentials' => ['https://user:secret@example.org/feed'];
        yield 'whitespace' => ["https://example.org/feed\nother"];
        yield 'missing host' => ['https:///feed'];
    }
}
