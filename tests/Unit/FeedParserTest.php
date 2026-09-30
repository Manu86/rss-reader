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

    public function testPlainTextDecodesHtmlEntities(): void
    {
        $xml = '<rss version="2.0"><channel>'
            . '<title>France 24 - Infos, news &amp;amp; actualités</title>'
            . '</channel></rss>';

        $feed = $this->parser->parse($xml, 'https://feeds.test/rss.xml');

        self::assertNotNull($feed);
        self::assertSame('France 24 - Infos, news & actualités', $feed->title);
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

    public function testArticleTagsAreParsedAndNormalized(): void
    {
        $rss = '<rss version="2.0">'
            . '<channel><title>Journal</title><item><title>Article</title>'
            . '<guid>tag-1</guid>'
            . '<category>Tech</category><category>  </category>'
            . '<category>Tech</category><category></category>'
            . '<category>' . str_repeat('a', 101) . '</category>'
            . '<category>Voyage</category>'
            . '</item></channel></rss>';
        $feed = $this->parser->parse($rss, 'https://feeds.test/rss.xml');

        self::assertNotNull($feed);
        self::assertSame(['Tech', 'Voyage'], $feed->articles[0]->tags);

        $atom = '<feed xmlns="http://www.w3.org/2005/Atom"><title>Journal</title>'
            . '<entry><title>Article</title><id>tag-1</id>'
            . '<category term="Web" label="Libellé ignoré"/><category label="Seul"/></entry></feed>';
        $atomFeed = $this->parser->parse($atom, 'https://feeds.test/atom.xml');

        self::assertNotNull($atomFeed);
        self::assertSame(['Web', 'Seul'], $atomFeed->articles[0]->tags);

        $plain = '<rss version="2.0"><channel><title>Journal</title>'
            . '<item><title>Article</title><guid>tag-2</guid></item></channel></rss>';
        $noTags = $this->parser->parse($plain, 'https://feeds.test/rss.xml');

        self::assertNotNull($noTags);
        self::assertSame([], $noTags->articles[0]->tags);
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

    public function testVideoMediaContentDoesNotMaskMediaThumbnail(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<feed xmlns="http://www.w3.org/2005/Atom" xmlns:media="http://search.yahoo.com/mrss/">
  <title>Contexte YouTube</title>
  <link rel="alternate" href="https://www.youtube.com/channel/UC123"/>
  <entry>
    <id>yt:video:AbCdEf12345</id>
    <title>Video de test</title>
    <link rel="alternate" href="https://www.youtube.com/watch?v=AbCdEf12345"/>
    <media:group>
      <media:title>Video de test</media:title>
      <media:content url="https://www.youtube.com/v/AbCdEf12345?version=3" type="application/x-shockwave-flash" width="640" height="390"/>
      <media:thumbnail url="https://i.ytimg.com/vi/AbCdEf12345/hqdefault.jpg" width="480" height="360"/>
    </media:group>
  </entry>
</feed>
XML;

        $feed = $this->parser->parse($xml, 'https://www.youtube.com/feeds/videos.xml?channel_id=UC123');

        self::assertNotNull($feed);
        self::assertSame('https://i.ytimg.com/vi/AbCdEf12345/hqdefault.jpg', $feed->articles[0]->imageUrl);
    }

    public function testImageMediaContentWithoutMediumIsStillSelected(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0" xmlns:media="http://search.yahoo.com/mrss/">
  <channel>
    <title>Journal</title>
    <item>
      <guid>media-content-image-1</guid>
      <title>Article image</title>
      <link>https://site.test/articles/1</link>
      <media:content url="https://cdn.test/images/cover.jpg" type="image/jpeg"/>
      <media:thumbnail url="https://cdn.test/images/thumb.jpg"/>
    </item>
    <item>
      <guid>media-content-video-2</guid>
      <title>Article video</title>
      <link>https://site.test/articles/2</link>
      <media:content url="https://cdn.test/videos/clip.mp4" medium="video" type="video/mp4"/>
      <media:thumbnail url="https://cdn.test/images/poster.jpg"/>
    </item>
  </channel>
</rss>
XML;

        $feed = $this->parser->parse($xml, 'https://feeds.test/rss.xml');

        self::assertNotNull($feed);
        self::assertSame('https://cdn.test/images/cover.jpg', $feed->articles[0]->imageUrl);
        self::assertSame('https://cdn.test/images/poster.jpg', $feed->articles[1]->imageUrl);
    }

    public function testItunesImagePodcastCoverIsUsedAsImageCandidate(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss xmlns:itunes="http://www.itunes.com/dtds/podcast-1.0.dtd" version="2.0">
  <channel>
    <title>Affaires étrangères</title>
    <link>https://www.radiofrance.fr/</link>
    <item>
      <guid>podcast-1</guid>
      <title>Épisode 1</title>
      <link>https://www.radiofrance.fr/</link>
      <itunes:image href="https://cdn.test/podcasts/episode-1.jpg"/>
    </item>
    <item>
      <guid>podcast-2</guid>
      <title>Épisode 2 sans visuel</title>
      <link>https://www.radiofrance.fr/</link>
    </item>
  </channel>
</rss>
XML;

        $feed = $this->parser->parse($xml, 'https://feeds.test/podcast.xml');

        self::assertNotNull($feed);
        self::assertSame('https://cdn.test/podcasts/episode-1.jpg', $feed->articles[0]->imageUrl);
        self::assertNull($feed->articles[1]->imageUrl);
    }

    public function testRssChannelImageIsStillAFaviconAndNotAnArticleCover(): void
    {
        $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<rss version="2.0">
  <channel>
    <title>Journal</title>
    <image>
      <url>https://cdn.test/channel-cover.jpg</url>
      <title>Journal</title>
    </image>
    <item>
      <guid>channel-1</guid>
      <title>Article 1</title>
      <link>https://site.test/articles/1</link>
    </item>
  </channel>
</rss>
XML;

        $feed = $this->parser->parse($xml, 'https://feeds.test/rss.xml');

        self::assertNotNull($feed);
        self::assertSame('https://cdn.test/channel-cover.jpg', $feed->faviconUrl);
        self::assertNull($feed->articles[0]->imageUrl);
    }

    public function testRssDublinCoreDateIsUsedAsPublicationDate(): void
    {
        $xml = '<rss version="2.0" xmlns:dc="http://purl.org/dc/elements/1.1/">'
            . '<channel><title>Journal</title><item><title>Article</title>'
            . '<link>https://site.test/article</link>'
            . '<dc:date>2026-09-30T09:05:13Z</dc:date>'
            . '</item></channel></rss>';

        $feed = $this->parser->parse($xml, 'https://feeds.test/rss.xml');

        self::assertNotNull($feed);
        self::assertSame('2026-09-30T09:05:13Z', $feed->articles[0]->publishedAt);
    }

    public function testLocalizedFrenchPublicationDateIsParsed(): void
    {
        $cases = [
            'Vendredi 25 septembre 2026 - 12:00' => '2026-09-25T12:00:00Z',
            '1er mars 2026 10:30' => '2026-03-01T10:30:00Z',
            'Lundi 1er septembre 2026 à 08:45' => '2026-09-01T08:45:00Z',
        ];
        foreach ($cases as $raw => $expected) {
            $xml = '<rss version="2.0"><channel><title>Journal</title>'
                . '<item><title>Article</title><link>https://site.test/article</link>'
                . '<pubDate>' . htmlspecialchars($raw) . '</pubDate>'
                . '</item></channel></rss>';

            $feed = $this->parser->parse($xml, 'https://feeds.test/rss.xml');

            self::assertNotNull($feed);
            self::assertSame($expected, $feed->articles[0]->publishedAt, "date : $raw");
        }
    }

    private function fixture(string $name): string
    {
        $content = file_get_contents(dirname(__DIR__) . '/Fixtures/Feeds/' . $name);
        self::assertIsString($content);

        return $content;
    }
}
