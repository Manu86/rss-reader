<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\UrlResolver;
use App\Service\ExternalHtmlTextSanitizer;
use App\Service\FeedParser;
use App\Validation\UrlNormalizer;
use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

final class FeedParserTest extends TestCase
{
    private FeedParser $parser;

    protected function setUp(): void
    {
        $this->parser = new FeedParser(
            new UrlResolver(),
            new UrlNormalizer(),
            new ExternalHtmlTextSanitizer(),
            new FrozenClock(new DateTimeImmutable('2026-09-24T12:00:00Z')),
        );
    }

    public function testRssIsNormalizedAndMalformedItemDoesNotBlockValidItems(): void
    {
        $feed = $this->parser->parse($this->fixture('sync-rss.xml'), 'https://feeds.test/rss.xml');

        self::assertNotNull($feed);
        self::assertSame('Journal sécurisé', $feed->title);
        self::assertSame('https://site.test/news/', $feed->siteUrl);
        self::assertCount(2, $feed->articles);
        $first = $feed->articles[0];
        self::assertSame('article-1', $first->guid);
        self::assertSame('2026-09-23T12:30:00Z', $first->publishedAt);
        self::assertSame('Alice', $first->author);
        self::assertSame('<p>Résumé <strong>utile</strong></p>', $first->summary);
        self::assertSame('<p>Contenu <a>complet</a></p>', $first->content);
        self::assertSame('https://feeds.test/articles/2', $feed->articles[1]->url);
        self::assertNull($feed->articles[1]->publishedAt);
        self::assertNotSame($first->deduplicationHash, $feed->articles[1]->deduplicationHash);
    }

    public function testAtomIsNormalized(): void
    {
        $feed = $this->parser->parse($this->fixture('sync-atom.xml'), 'https://feeds.test/atom.xml');

        self::assertNotNull($feed);
        self::assertSame('Journal Atom', $feed->title);
        self::assertSame('https://site.test/atom/', $feed->siteUrl);
        self::assertCount(1, $feed->articles);
        self::assertSame('urn:article:atom:1', $feed->articles[0]->guid);
        self::assertSame('https://feeds.test/atom/article-1', $feed->articles[0]->url);
        self::assertSame('2026-09-23T08:15:00Z', $feed->articles[0]->publishedAt);
        self::assertSame('<p>Résumé Atom</p>', $feed->articles[0]->summary);
    }

    public function testPlainTextMarkdownIsConvertedBeforeSafeMarkupStorage(): void
    {
        $xml = '<rss version="2.0" xmlns:content="http://purl.org/rss/1.0/modules/content/">'
            . '<channel><title>Journal</title><item><title>Article</title>'
            . '<link>https://site.test/article</link><description><![CDATA['
            . "## Intertitre\n\nTexte **important** et ~~barré~~.\n\n> Citation"
            . ']]></description><content:encoded><![CDATA['
            . "| Colonne | Valeur |\n| --- | --- |\n| A | B |\n\n- [ ] Tâche"
            . ']]></content:encoded></item></channel></rss>';

        $feed = $this->parser->parse($xml, 'https://feeds.test/rss.xml');

        self::assertNotNull($feed);
        self::assertStringContainsString('<h2>Intertitre</h2>', (string) $feed->articles[0]->summary);
        self::assertStringContainsString('<strong>important</strong>', (string) $feed->articles[0]->summary);
        self::assertStringContainsString('<del>barré</del>', (string) $feed->articles[0]->summary);
        self::assertStringContainsString('<blockquote>', (string) $feed->articles[0]->summary);
        self::assertStringContainsString('<table>', (string) $feed->articles[0]->content);
        self::assertStringContainsString('<li>Tâche</li>', (string) $feed->articles[0]->content);
    }

    public function testDoctypeAndUnsupportedDocumentsAreRejected(): void
    {
        self::assertNull($this->parser->parse(
            '<!DOCTYPE rss [<!ENTITY x SYSTEM "file:///etc/passwd">]>'
                . '<rss version="2.0"><channel><title>&x;</title></channel></rss>',
            'https://feeds.test/rss.xml',
        ));
        self::assertNull($this->parser->parse('<rss version="0.92"/>', 'https://feeds.test/rss.xml'));
        self::assertNull($this->parser->parse('<not-a-feed/>', 'https://feeds.test/rss.xml'));
    }

    public function testImageAndFaviconCandidatesAreNormalizedWithoutTrustingMarkup(): void
    {
        $feed = $this->parser->parse($this->fixture('media-rss.xml'), 'https://feeds.test/path/feed.xml');

        self::assertNotNull($feed);
        self::assertSame('https://feeds.test/assets/favicon.png', $feed->faviconUrl);
        self::assertSame('https://cdn.test/images/large.png', $feed->articles[0]->imageUrl);
        self::assertSame('https://site.test/images/content.jpg', $feed->articles[1]->imageUrl);
        self::assertSame(
            '<p>Texte</p><img src="https://site.test/images/content.jpg" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer">',
            $feed->articles[1]->summary,
        );

        $atom = $this->parser->parse($this->fixture('media-atom.xml'), 'https://feeds.test/path/feed.atom');
        self::assertNotNull($atom);
        self::assertSame('https://feeds.test/icons/atom.png', $atom->faviconUrl);
        self::assertSame('https://feeds.test/images/atom.jpg', $atom->articles[0]->imageUrl);
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/Fixtures/Feeds/' . $name);
        self::assertIsString($content);

        return $content;
    }
}
