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
use App\Service\ArticleCoverDeduplicator;
use App\Service\ArticleDuplicateCoverService;
use App\Service\RemoteMediaService;
use App\Validation\UrlNormalizer;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FakeDnsResolver;
use Tests\Support\FakeHttpTransport;
use Tests\Support\FrozenClock;
use Tests\Support\MemoryMediaStorage;

final class ArticleDuplicateCoverServiceTest extends TestCase
{
    private PDO $pdo;
    private MemoryMediaStorage $media;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->insertUser(1, 'alice');
        $this->insertUser(2, 'bob');
        $this->media = new MemoryMediaStorage();
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

    public function testRepeatedCoverIsStrippedAndContentSourceIsKept(): void
    {
        $artwork = $this->gradientImage();
        $cover = $this->media->store(1, $artwork['jpeg'], 'png');
        $feedId = $this->insertFeed(10, 1);
        $repeated = $this->insertArticle(1, $feedId, 'Article doublé', 'page', $cover);
        $clean = $this->insertArticle(1, $feedId, 'Article intact', 'page', null);

        $summary = $this->service([new TransportResponse(
            200,
            ['content-type' => 'image/png'],
            $artwork['png'],
        )])->cleanup();

        self::assertSame(1, $summary['checked']);
        self::assertSame(1, $summary['cleaned']);
        $content = $this->contentOf($repeated);
        self::assertStringNotContainsString('visuel-copie.png', $content);
        self::assertStringContainsString('annexe.png', $content);
        self::assertSame('page', $this->scalar('SELECT content_source FROM articles WHERE id = ' . $repeated));
        self::assertSame('page', $this->scalar('SELECT content_source FROM articles WHERE id = ' . $clean));
        self::assertStringContainsString('visuel-copie.png', $this->contentOf($clean));
    }

    public function testContentWithoutCoverIsNotStripped(): void
    {
        $artwork = $this->gradientImage();
        $this->insertFeed(10, 1);
        $this->insertFeed(20, 2);
        $covered = $this->insertArticle(1, 10, 'Article couvert', 'feed', $this->media->store(1, $artwork['jpeg'], 'png'));
        $bare = $this->insertArticle(2, 20, 'Article sans couverture', 'feed', null);
        $this->normalizeContent($bare, '<p>Synthèse.</p><img src="https://feeds.test/visuel-copie.png">');

        $summary = $this->service([new TransportResponse(
            200,
            ['content-type' => 'image/png'],
            $artwork['png'],
        )])->cleanup();

        self::assertSame(1, $summary['checked']);
        self::assertSame(1, $summary['cleaned']);
        self::assertStringNotContainsString('visuel-copie.png', $this->contentOf($covered));
        self::assertStringContainsString('visuel-copie.png', $this->contentOf($bare));
    }

    public function testUnreachableArtworkLeavesContentUntouched(): void
    {
        $artwork = $this->gradientImage();
        $this->insertFeed(10, 1);
        $this->insertArticle(1, 10, 'Article doublé', 'page', $this->media->store(1, $artwork['jpeg'], 'png'));

        $summary = $this->service([new TransportResponse(410, [], '')])->cleanup();

        self::assertSame(1, $summary['checked']);
        self::assertSame(0, $summary['cleaned']);
    }

    /**
     * @param list<TransportResponse> $responses
     */
    private function service(array $responses): ArticleDuplicateCoverService
    {
        $http = new SafeHttpClient(
            new RemoteUrlGuard(new UrlNormalizer(), new FakeDnsResolver([
                'feeds.test' => ['93.184.216.50'],
            ]), new IpAddressValidator()),
            new FakeHttpTransport($responses),
            new UrlResolver(),
            100,
            500,
            1_000_000,
            2,
            'RSSReader/Test',
        );
        $remoteMedia = new RemoteMediaService($http, $this->media, 1_000_000, 4096, 4096, 16_777_216);

        return new ArticleDuplicateCoverService(
            new ArticleRepository($this->pdo),
            $this->media,
            new ArticleCoverDeduplicator($remoteMedia, $this->media, new UrlNormalizer()),
            new FrozenClock(new DateTimeImmutable('2026-09-24T12:00:00Z')),
        );
    }

    private function insertFeed(int $id, int $userId): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(id, user_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:id, :user_id, :name, :feed_url, 1, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => 'Flux ' . $id,
            'feed_url' => 'https://feeds.test/' . $id . '.xml',
            'status' => 'never',
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);

        return $id;
    }

    private function insertArticle(int $userId, int $feedId, string $title, ?string $contentSource, ?string $imagePath): int
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles '
            . '(user_id, feed_id, title, discovered_at, image_path, content, content_source, '
            . 'deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :title, :discovered_at, :image_path, :content, :content_source, '
            . ':hash, :created_at, :updated_at)'
        );
        $content = '<p>Texte de synthèse de l’article ' . $title . '.</p>';
        if ($contentSource === 'page' || $contentSource === 'feed') {
            $content .= '<img src="https://feeds.test/visuel-copie.png"><img src="https://feeds.test/annexe.png">';
        }
        $statement->execute([
            'user_id' => $userId,
            'feed_id' => $feedId,
            'title' => $title,
            'discovered_at' => '2026-09-24T12:00:00Z',
            'image_path' => $imagePath,
            'content' => $content,
            'content_source' => $contentSource,
            'hash' => hash('sha256', $userId . ':' . $feedId . ':' . $title),
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    private function normalizeContent(int $articleId, string $content): void
    {
        $this->pdo->prepare('UPDATE articles SET content = :content WHERE id = :id')->execute([
            'content' => $content,
            'id' => $articleId,
        ]);
    }

    private function contentOf(int $articleId): string
    {
        $statement = $this->pdo->query('SELECT content FROM articles WHERE id = ' . $articleId);
        self::assertNotFalse($statement);
        $content = $statement->fetchColumn();
        self::assertIsString($content);

        return $content;
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

    /** @return array{png: string, jpeg: string} */
    private function gradientImage(): array
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
        ob_start();
        imagejpeg($image, null, 82);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return ['png' => $png, 'jpeg' => $jpeg];
    }
}
