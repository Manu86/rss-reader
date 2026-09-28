<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Model\Article;
use App\Repository\ArticleRepository;
use App\Service\StoredRecommendationImageProvider;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\MemoryMediaStorage;

final class StoredRecommendationImageProviderTest extends TestCase
{
    public function testItCreatesSmallOwnedSquareEmailThumbnail(): void
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->exec('CREATE TABLE articles (id INTEGER PRIMARY KEY, user_id INTEGER NOT NULL, image_path TEXT)');
        $storage = new MemoryMediaStorage();
        $source = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=',
            true,
        );
        self::assertIsString($source);
        $path = $storage->store(7, $source, 'png');
        $statement = $pdo->prepare('INSERT INTO articles (id, user_id, image_path) VALUES (42, 7, :path)');
        $statement->execute(['path' => $path]);
        $provider = new StoredRecommendationImageProvider(new ArticleRepository($pdo), $storage);
        $article = new Article(
            42,
            10,
            'Source',
            false,
            null,
            null,
            'Article',
            null,
            null,
            null,
            '2026-09-28T05:00:00Z',
            null,
            null,
            [],
            true,
        );

        self::assertNull($provider->forArticle(8, $article));
        $thumbnail = $provider->forArticle(7, $article);

        self::assertNotNull($thumbnail);
        self::assertSame('image/jpeg', $thumbnail->contentType);
        self::assertLessThanOrEqual(100_000, strlen($thumbnail->content));
        $info = getimagesizefromstring($thumbnail->content);
        self::assertIsArray($info);
        self::assertSame(88, $info[0]);
        self::assertSame(88, $info[1]);
    }
}
