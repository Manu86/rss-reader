<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\UrlResolver;
use App\Service\ArticlePageContentParser;
use App\Service\ExternalHtmlTextSanitizer;
use App\Validation\UrlNormalizer;
use PHPUnit\Framework\TestCase;

final class ArticlePageContentParserTest extends TestCase
{
    private ArticlePageContentParser $parser;

    protected function setUp(): void
    {
        $this->parser = new ArticlePageContentParser(
            new UrlResolver(),
            new UrlNormalizer(),
            new ExternalHtmlTextSanitizer(),
        );
    }

    public function testNewsArticleJsonLdBodyIsPreferred(): void
    {
        $body = str_repeat('Contenu structuré public. ', 12);
        $html = '<script type="application/ld+json">'
            . json_encode(['@type' => 'NewsArticle', 'articleBody' => $body], JSON_THROW_ON_ERROR)
            . '</script><article><p>' . str_repeat('Autre contenu. ', 30) . '</p></article>';

        $content = $this->parser->parse($html, 'https://site.test/article');

        self::assertNotNull($content);
        self::assertStringContainsString('Contenu structuré public.', $content);
        self::assertStringNotContainsString('Autre contenu.', $content);
    }

    public function testEncodedHtmlInJsonLdBodyIsDecodedBeforeSanitizing(): void
    {
        $body = 'Pas d’incendie&amp;nbsp;à la gare de '
            . '&lt;a href=&quot;https://site.test/poitiers&quot;&gt;Poitiers&lt;/a&gt;. '
            . str_repeat('Suite publique. ', 15);
        $html = '<script type="application/ld+json">'
            . json_encode(['@type' => 'NewsArticle', 'articleBody' => $body], JSON_THROW_ON_ERROR)
            . '</script>';

        $content = $this->parser->parse($html, 'https://site.test/article');

        self::assertNotNull($content);
        self::assertStringContainsString('href="https://site.test/poitiers"', $content);
        self::assertStringNotContainsString('&amp;lt;', $content);
        self::assertStringNotContainsString('&amp;nbsp;', $content);
    }

    public function testSemanticHtmlIsSanitizedAndRelativeUrlsAreResolved(): void
    {
        $text = 'Coup de tonnerre dans les réseaux poitevins de solidarité. ' . str_repeat('Suite publique. ', 15);
        $html = '<article><header>Navigation</header><div data-testid="contenu-article">'
            . '<p>' . $text . '<script>alert(1)</script></p>'
            . '<a href="/suite" onclick="alert(1)">Suite</a>'
            . '<div class="related">Contenu associé à écarter</div>'
            . '</div></article>';

        $content = $this->parser->parse($html, 'https://site.test/articles/a');

        self::assertNotNull($content);
        self::assertStringContainsString('Coup de tonnerre', $content);
        self::assertStringContainsString('href="https://site.test/suite"', $content);
        self::assertStringNotContainsString('script', $content);
        self::assertStringNotContainsString('onclick', $content);
        self::assertStringNotContainsString('Contenu associé', $content);
    }

    public function testShortOrUnrelatedPageHasNoExtractedContent(): void
    {
        self::assertNull($this->parser->parse(
            '<main><p>Une page sans article.</p></main>',
            'https://site.test/page',
        ));
        self::assertNull($this->parser->parse(
            '<article><p>' . str_repeat('a', 199) . '</p></article>',
            'https://site.test/article',
        ));
    }
}
