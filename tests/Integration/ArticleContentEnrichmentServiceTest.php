<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Http\SafeHttpClient;
use App\Http\TransportResponse;
use App\Http\UrlResolver;
use App\Repository\ArticleRepository;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Service\ArticleContentEnrichmentService;
use App\Service\ArticleCoverDeduplicator;
use App\Service\ArticleImageMetadataParser;
use App\Service\ArticlePageContentParser;
use App\Service\ArticlePageService;
use App\Service\ExternalHtmlTextSanitizer;
use App\Service\RemoteMediaService;
use App\Validation\UrlNormalizer;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeHttpTransport;
use Tests\Support\FrozenClock;
use Tests\Support\MemoryMediaStorage;

final class ArticleContentEnrichmentServiceTest extends TestCase
{
    private PDO $pdo;
    private MemoryMediaStorage $media;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->insertUser(1, 'alice');
        $this->insertFeed(10, 1);
        $this->media = new MemoryMediaStorage();
    }

    /**
     * @param list<TransportResponse> $responses
     */
    private function service(array $responses): ArticleContentEnrichmentService
    {
        $urlResolver = new UrlResolver();
        $urlNormalizer = new UrlNormalizer();
        $http = new SafeHttpClient(
            new RemoteUrlGuard($urlNormalizer, new FakeDnsResolver([
                'site.test' => ['93.184.216.60'],
            ]), new IpAddressValidator()),
            new FakeHttpTransport($responses),
            $urlResolver,
            100,
            500,
            1_000_000,
            2,
            'Mozilla/5.0 RSSReader/Page-Test',
        );
        $remoteMedia = new RemoteMediaService($http, $this->media, 1_000_000, 4096, 4096, 16_777_216);
        $deduplicator = new ArticleCoverDeduplicator($remoteMedia, $this->media, $urlNormalizer);

        return new ArticleContentEnrichmentService(
            new ArticleRepository($this->pdo),
            new ArticlePageService(
                $http,
                new ArticlePageContentParser($urlResolver, $urlNormalizer, new ExternalHtmlTextSanitizer()),
                new ArticleImageMetadataParser($urlResolver),
                'Mozilla/5.0 RSSReader/Page-Test',
            ),
            $deduplicator,
            $this->media,
            new ExternalHtmlTextSanitizer(),
            new FrozenClock(new DateTimeImmutable('2026-09-24T12:00:00Z')),
        );
    }

    public function testExtractedContentIsStoredAndMarkedInspected(): void
    {
        $text = str_repeat('Contenu public à enrichir. ', 12);
        $articleId = $this->insertArticle(1, 10, 'Article pauvre', null);

        $summary = $this->service([
            new TransportResponse(200, ['content-type' => 'text/html'], '<article><p>' . $text . '</p></article>'),
        ])->run();

        self::assertSame(1, $summary['total']);
        self::assertSame(1, $summary['extracted']);
        self::assertSame('page', $this->scalar('SELECT content_source FROM articles WHERE id = ' . $articleId));
        self::assertStringContainsString('Contenu public', $this->content($articleId));
        self::assertNotNull($this->scalar('SELECT content_page_checked_at FROM articles WHERE id = ' . $articleId));
    }

    public function testStoredCoverIsNotRepeatedInTheExtractedContent(): void
    {
        $text = str_repeat('Contenu public à enrichir. ', 12);
        $coverBytes = $this->gradientImage();
        $articleId = $this->insertArticle(1, 10, 'Article doublé', $this->media->store(1, $coverBytes, 'png'));

        $summary = $this->service([
            new TransportResponse(
                200,
                ['content-type' => 'text/html'],
                '<html><head><meta property="og:image" content="/photos/visuel.png"></head><body>'
                    . '<article><img src="/photos/visuel.jpg"><img src="/photos/annexe.png">'
                    . '<p>' . $text . '</p></article></body></html>',
            ),
        ])->run();

        self::assertSame(1, $summary['extracted']);
        $content = $this->content($articleId);
        self::assertStringNotContainsString('/photos/visuel.jpg', $content);
        self::assertStringContainsString('/photos/annexe.png', $content);
        self::assertStringContainsString('Contenu public', $content);
    }

    private function insertUser(int $id, string $username): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'username' => $username,
            'password_hash' => password_hash('password-for-tests', PASSWORD_DEFAULT),
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);
    }

    private function insertFeed(int $id, int $userId): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds (id, user_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:id, :user_id, :name, :feed_url, 1, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => 'Flux ' . $id,
            'feed_url' => 'https://feeds.test/feed.xml',
            'status' => 'never',
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);
    }

    private function insertArticle(int $userId, int $feedId, string $title, ?string $imagePath): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles (user_id, feed_id, title, url, summary, discovered_at, image_path, '
            . 'deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :title, :url, :summary, :discovered_at, :image_path, '
            . ':hash, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'feed_id' => $feedId,
            'title' => $title,
            'url' => 'https://site.test/articles/' . rawurlencode($title) . '-' . random_int(1, 100000),
            'summary' => '<p>Résumé court.</p>',
            'discovered_at' => '2026-09-24T12:00:00Z',
            'image_path' => $imagePath,
            'hash' => hash('sha256', $userId . ':' . $feedId . ':' . $title),
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function content(int $id): string
    {
        $statement = $this->pdo->query('SELECT content FROM articles WHERE id = ' . $id);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        self::assertIsString($value);

        return $value;
    }

    private function scalar(string $sql): ?string
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);
        $value = $statement->fetchColumn();
        if ($value === false || $value === null) {
            return null;
        }

        return (string) $value;
    }

    private function gradientImage(): string
    {
        $image = imagecreatetruecolor(64, 64);
        for ($x = 0; $x < 64; ++$x) {
            $level = (int) ($x / 64 * 255);
            if ($level < 0 || $level > 255) {
                $level = 0;
            }
            $color = imagecolorallocate($image, $level, $level, $level);
            if (!is_int($color)) {
                $color = 0;
            }
            imageline($image, $x, 0, $x, 63, $color);
        }
        ob_start();
        imagepng($image);
        $png = (string) ob_get_clean();
        imagedestroy($image);

        return $png;
    }
}
