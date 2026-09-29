<?php

declare(strict_types=1);

namespace Tests\Security;

use App\Http\SafeHttpClient;
use App\Http\TransportResponse;
use App\Http\UrlResolver;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Service\ArticleImageMetadataParser;
use App\Service\ArticlePageContentParser;
use App\Service\ArticlePageService;
use App\Service\ExternalHtmlTextSanitizer;
use App\Validation\UrlNormalizer;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeHttpTransport;

final class ArticlePageServiceTest extends TestCase
{
    public function testPageIsFetchedOnceWithTheDedicatedUserAgent(): void
    {
        $transport = new FakeHttpTransport([new TransportResponse(
            200,
            ['content-type' => 'text/html'],
            '<article><p>' . str_repeat('Contenu public. ', 20) . '</p></article>'
                . '<meta property="og:image" content="/image.jpg">',
        )]);
        $resolver = new UrlResolver();
        $normalizer = new UrlNormalizer();
        $http = new SafeHttpClient(
            new RemoteUrlGuard($normalizer, new FakeDnsResolver([
                'site.test' => ['93.184.216.34'],
            ]), new IpAddressValidator()),
            $transport,
            $resolver,
            100,
            500,
            1_000_000,
            2,
            'RSSReader/Test',
        );
        $service = new ArticlePageService(
            $http,
            new ArticlePageContentParser($resolver, $normalizer, new ExternalHtmlTextSanitizer()),
            new ArticleImageMetadataParser($resolver),
            'Mozilla/5.0 RSSReader/Page-Test',
        );

        $page = $service->fetch('https://site.test/article');

        self::assertNotNull($page->content);
        self::assertSame([['url' => 'https://site.test/image.jpg', 'min_side' => 1]], $page->imageCandidates);
        self::assertCount(1, $transport->requests);
        self::assertSame('Mozilla/5.0 RSSReader/Page-Test', $transport->requests[0]->userAgent);
    }
}
