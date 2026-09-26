<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\UrlResolver;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UrlResolverTest extends TestCase
{
    #[DataProvider('redirects')]
    public function testItResolvesRedirectLocations(string $base, string $location, string $expected): void
    {
        self::assertSame($expected, (new UrlResolver())->resolve($base, $location));
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function redirects(): iterable
    {
        yield 'absolute' => [
            'https://example.org/path/start',
            'https://feeds.example.net/rss',
            'https://feeds.example.net/rss',
        ];
        yield 'protocol relative' => [
            'https://example.org/path/start',
            '//cdn.example.net/rss',
            'https://cdn.example.net/rss',
        ];
        yield 'root relative' => [
            'https://example.org/path/start',
            '/rss.xml',
            'https://example.org/rss.xml',
        ];
        yield 'path relative' => [
            'https://example.org/path/start',
            '../rss.xml?full=1',
            'https://example.org/rss.xml?full=1',
        ];
        yield 'query only' => [
            'https://example.org/path/start',
            '?format=atom',
            'https://example.org/path/start?format=atom',
        ];
    }
}
