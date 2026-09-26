<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repository\ArticleRetentionRepository;
use App\Repository\MediaReferenceRepository;
use App\Service\ArticleRetentionService;
use App\Service\MediaCleanupService;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;
use Tests\Support\MemoryMediaStorage;

final class ArticleRetentionServiceTest extends TestCase
{
    private PDO $pdo;
    private MemoryMediaStorage $media;
    private ArticleRetentionService $retention;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->insertUser(1, 'alice');
        $this->insertUser(2, 'bob');
        $this->insertFeed(10, 1);
        $this->insertFeed(20, 2);
        $this->media = new MemoryMediaStorage();
        $this->retention = new ArticleRetentionService(
            new ArticleRetentionRepository($this->pdo),
            new MediaCleanupService(new MediaReferenceRepository($this->pdo), $this->media),
            new FrozenClock(new DateTimeImmutable('2026-09-24T12:00:00Z')),
        );
    }

    public function testCleanupUsesReferenceDateKeepsFavoritesAndRemovesOrphanMedia(): void
    {
        $expiredImage = $this->media->store(1, 'expired', 'jpg');
        $sharedImage = $this->media->store(1, 'shared', 'png');
        $favoriteImage = $this->media->store(1, 'favorite', 'webp');
        $bobExpiredImage = $this->media->store(2, 'bob-expired', 'gif');

        $expiredPublished = $this->insertArticle(
            1,
            10,
            'Publié ancien',
            '2025-09-23T11:59:59Z',
            '2026-09-24T11:00:00Z',
            false,
            $expiredImage,
        );
        $boundary = $this->insertArticle(
            1,
            10,
            'Exactement un an',
            '2025-09-24T12:00:00Z',
            '2025-09-24T12:00:00Z',
            false,
            null,
        );
        $young = $this->insertArticle(
            1,
            10,
            'Article récent',
            '2025-09-24T12:00:01Z',
            '2025-09-24T12:00:01Z',
            false,
            null,
        );
        $favorite = $this->insertArticle(
            1,
            10,
            'Favori ancien',
            '2024-01-01T00:00:00Z',
            '2024-01-01T00:00:00Z',
            true,
            $favoriteImage,
        );
        $expiredFallback = $this->insertArticle(
            1,
            10,
            'Découverte ancienne',
            null,
            '2025-09-23T12:00:00Z',
            false,
            $sharedImage,
        );
        $youngFallback = $this->insertArticle(
            1,
            10,
            'Découverte récente',
            null,
            '2026-01-01T00:00:00Z',
            false,
            $sharedImage,
        );
        $bobExpired = $this->insertArticle(
            2,
            20,
            'Ancien Bob',
            null,
            '2025-01-01T00:00:00Z',
            false,
            $bobExpiredImage,
        );

        self::assertSame(3, $this->retention->cleanup());
        self::assertSame(0, $this->retention->cleanup());
        self::assertSame(
            [$boundary, $young, $favorite, $youngFallback],
            $this->articleIds(),
        );
        self::assertNotContains($expiredPublished, $this->articleIds());
        self::assertNotContains($expiredFallback, $this->articleIds());
        self::assertNotContains($bobExpired, $this->articleIds());

        self::assertNull($this->media->read(1, $expiredImage));
        self::assertNotNull($this->media->read(1, $sharedImage));
        self::assertNotNull($this->media->read(1, $favoriteImage));
        self::assertNull($this->media->read(2, $bobExpiredImage));

        self::assertSame(0, $this->ftsCount('Publié'));
        self::assertSame(0, $this->ftsCount('ancienne'));
        self::assertSame(1, $this->ftsCount('Favori'));
        self::assertSame(1, $this->ftsCount('récente'));
    }

    public function testCleanupProcessesMoreThanOneBatch(): void
    {
        for ($index = 1; $index <= 501; ++$index) {
            $this->insertArticle(
                1,
                10,
                'Ancien lot ' . $index,
                null,
                '2024-01-01T00:00:00Z',
                false,
                null,
            );
        }

        self::assertSame(501, $this->retention->cleanup());
        self::assertSame([], $this->articleIds());
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
    }

    private function insertArticle(
        int $userId,
        int $feedId,
        string $title,
        ?string $publishedAt,
        string $discoveredAt,
        bool $favorite,
        ?string $imagePath,
    ): int {
        $statement = $this->pdo->prepare(
            'INSERT INTO articles '
            . '(user_id, feed_id, title, published_at, discovered_at, image_path, is_favorite, '
            . 'deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :title, :published_at, :discovered_at, :image_path, '
            . ':is_favorite, :hash, :created_at, :updated_at)'
        );
        $hash = hash('sha256', $userId . ':' . $feedId . ':' . $title);
        $statement->execute([
            'user_id' => $userId,
            'feed_id' => $feedId,
            'title' => $title,
            'published_at' => $publishedAt,
            'discovered_at' => $discoveredAt,
            'image_path' => $imagePath,
            'is_favorite' => $favorite ? 1 : 0,
            'hash' => $hash,
            'created_at' => $discoveredAt,
            'updated_at' => $discoveredAt,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    /** @return list<int> */
    private function articleIds(): array
    {
        $statement = $this->pdo->query('SELECT id FROM articles ORDER BY id');
        self::assertNotFalse($statement);

        return array_values(array_map('intval', $statement->fetchAll(PDO::FETCH_COLUMN)));
    }

    private function ftsCount(string $term): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM articles_fts WHERE articles_fts MATCH :term'
        );
        $statement->execute(['term' => $term]);

        return (int) $statement->fetchColumn();
    }
}
