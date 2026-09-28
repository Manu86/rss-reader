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
}
