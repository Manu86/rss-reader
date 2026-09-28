<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\UrlResolver;
use App\Service\ArticleImageMetadataParser;
use PHPUnit\Framework\TestCase;

final class ArticleImageMetadataParserTest extends TestCase
{
    public function testStandardImageMetadataIsResolvedAndDeduplicated(): void
    {
        $parser = new ArticleImageMetadataParser(new UrlResolver());

        self::assertSame([
            'https://site.test/images/cover.jpg',
            'https://cdn.test/social.png',
        ], $parser->parse(
            '<html><head><base href="https://site.test/articles/">'
                . '<meta property="og:image" content="../images/cover.jpg">'
                . '<meta name="twitter:image" content="https://cdn.test/social.png">'
                . '<meta property="og:image:url" content="../images/cover.jpg">'
                . '<meta property="og:description" content="Ignored"></head></html>',
            'https://site.test/article',
        ));
    }

    public function testUnsafeOrEmptyMetadataIsIgnored(): void
    {
        $parser = new ArticleImageMetadataParser(new UrlResolver());

        self::assertSame([], $parser->parse(
            '<meta property="og:image" content="javascript:alert(1)">'
                . '<meta name="twitter:image" content="">',
            'https://site.test/article',
        ));
    }

    public function testContentImagesSkipNavigationAndDecorativeFiles(): void
    {
        $parser = new ArticleImageMetadataParser(new UrlResolver());

        self::assertSame([
            'https://site.test/sites/default/files/adn.jpg',
        ], $parser->parseContentImages(
            '<html><body><header><img src="/logo.png" width="225" height="141"></header>'
                . '<nav><img src="/icone.svg" width="400" height="400"></nav>'
                . '<article><img src="/sites/default/files/adn.jpg"></article>'
                . '<img src="/sites/default/files/peertube_logo.png" width="600" height="400">'
                . '<footer><img src="/pied.jpg"></footer></body></html>'
                . '<aside><img src="/related.jpg"></aside>',
            'https://site.test/article',
        ));
    }

    public function testContentImagesDiscardSmallDeclaredSizes(): void
    {
        $parser = new ArticleImageMetadataParser(new UrlResolver());

        self::assertSame([], $parser->parseContentImages(
            '<img src="/partenaire.png" width="385" height="141">'
                . '<img src="/auteur.jpg" width="140" height="140">'
                . '<img src="/pastille.png" width="18" height="17">',
            'https://site.test/article',
        ));
        self::assertSame(
            ['https://site.test/illustration.png'],
            $parser->parseContentImages(
                '<img src="/illustration.png" width="385" height="200">',
                'https://site.test/article',
            ),
        );
    }

    public function testContentImagesFollowTheDocumentOrder(): void
    {
        $parser = new ArticleImageMetadataParser(new UrlResolver());

        self::assertSame([
            'https://site.test/premiere.jpg',
            'https://site.test/seconde.jpg',
        ], $parser->parseContentImages(
            '<img src="/premiere.jpg"><img src="/seconde.jpg">',
            'https://site.test/article',
        ));
    }

    public function testContentImagesResolveLazyLoadedSourcesAndUnsafeSchemes(): void
    {
        $parser = new ArticleImageMetadataParser(new UrlResolver());

        self::assertSame(
            ['https://site.test/images/lazy.png'],
            $parser->parseContentImages(
                '<img data-src="/images/lazy.png"><img src="javascript:alert(1)"><img src="">',
                'https://site.test/article',
            ),
        );
    }

    public function testContentImagesAreBounded(): void
    {
        $parser = new ArticleImageMetadataParser(new UrlResolver());
        $images = '';
        for ($index = 1; $index <= 9; $index++) {
            $images .= sprintf('<img src="/image-%d.jpg">', $index);
        }

        self::assertCount(5, $parser->parseContentImages($images, 'https://site.test/article'));
    }
}
