<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Repository\ArticleRepository;
use PDO;
use PDOException;
use PHPUnit\Framework\TestCase;

final class MigrationTest extends TestCase
{
    private PDO $pdo;
    private Migrator $migrator;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        $this->migrator = new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations');
    }

    public function testFreshDatabaseCanBeMigratedIdempotently(): void
    {
        self::assertSame(
            [
                '001_initial_schema',
                '002_remote_action_attempts',
                '003_user_settings_theme',
                '004_article_tags',
                '005_user_remember_tokens',
                '006_users_email',
                '007_recommendation_email_delivery',
                '008_article_image_metadata_checked',
                '009_merge_republished_articles',
                '010_article_favorited_at',
                '011_retry_article_image_metadata',
            ],
            $this->migrator->migrate(),
        );
        self::assertSame([], $this->migrator->migrate());
        self::assertSame(1, $this->integerQuery('PRAGMA foreign_keys'));
        self::assertSame(1, $this->integerQuery(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'articles_fts'"
        ));
    }

    public function testRepublishedArticlesAreMergedAndRebroadcastsAreKept(): void
    {
        $this->migrator->migrate();
        $this->insertUsers();
        $this->pdo->exec("INSERT INTO feeds (id, user_id, name, feed_url, created_at, updated_at) "
            . "VALUES (1, 1, 'Flux', 'https://example.org/feed', '2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')");
        $this->insertArticle(630, 'guid-a', 'https://example.org/a', 'Un article', '2026-09-27T18:24:18Z', 1, 0);
        $this->insertArticle(631, 'guid-b', 'https://example.org/a', 'Un article', '2026-09-27T18:23:54Z', 0, 1);
        $this->insertArticle(674, 'guid-c', 'https://example.org/b', 'Emission', '2026-09-28T08:15:07Z', 0, 0);
        $this->insertArticle(683, 'guid-d', 'https://example.org/b', 'Emission', '2026-10-05T08:15:07Z', 0, 0);

        $this->migrator->migrate();
        $this->pdo->exec('DELETE FROM schema_migrations WHERE version = \'009_merge_republished_articles\'');
        $this->migrator->migrate();

        $ids = $this->idList();
        self::assertNotContains(631, $ids, 'Le republication du meme jour doit etre fusionnee');
        self::assertContains(630, $ids);
        self::assertContains(683, $ids, 'Une rediffusion ulterieure reste un article distinct');
        self::assertContains(674, $ids);
        self::assertCount(3, $ids);

        $kept = $this->row(630);
        self::assertSame(1, (int) $kept['is_read'], 'L etat de lecture du republication est conserve');
        self::assertSame(1, (int) $kept['is_favorite'], 'L etat de favori du republication est conserve');
        self::assertSame('guid-a', $kept['guid'], 'Le plus ancien article est conserve');
    }

    public function testUserSettingsThemeRejectsUnknownValues(): void
    {
        $this->migrator->migrate();
        $this->insertUsers();

        self::assertSame(1, $this->pdo->exec(
            "INSERT INTO user_settings (user_id, articles_per_page, theme, created_at, updated_at) "
            . "VALUES (1, 25, 'dark', '2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        ));

        $this->expectException(PDOException::class);
        try {
            $this->pdo->exec("UPDATE user_settings SET theme = 'noir' WHERE user_id = 1");
        } catch (PDOException $error) {
            throw $error;
        }
    }

    public function testRecommendationEmailFrequencyRejectsUnknownValues(): void
    {
        $this->migrator->migrate();
        $this->insertUsers();
        $this->pdo->exec(
            "INSERT INTO user_settings (user_id, articles_per_page, theme, created_at, updated_at) "
            . "VALUES (1, 25, 'light', '2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );

        $this->expectException(PDOException::class);
        $this->pdo->exec(
            "UPDATE user_settings SET recommendation_email_frequency = 'hourly' WHERE user_id = 1"
        );
    }

    public function testFeedCannotUseAnotherUsersCategory(): void
    {
        $this->migrator->migrate();
        $this->insertUsers();
        $this->pdo->exec(
            "INSERT INTO categories (id, user_id, name, created_at, updated_at) "
            . "VALUES (10, 2, 'Privée', '2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );

        $this->expectException(PDOException::class);
        $this->pdo->exec(
            "INSERT INTO feeds (user_id, category_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) "
            . "VALUES (1, 10, 'Flux', 'https://example.org/feed', 1, 'never', "
            . "'2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );
    }

    public function testArticleCannotUseAnotherUsersFeed(): void
    {
        $this->migrator->migrate();
        $this->insertUsers();
        $this->pdo->exec(
            "INSERT INTO feeds (id, user_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) "
            . "VALUES (20, 2, 'Flux B', 'https://example.org/b', 1, 'never', "
            . "'2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );

        $this->expectException(PDOException::class);
        $this->pdo->exec(
            "INSERT INTO articles (user_id, feed_id, title, discovered_at, deduplication_hash, created_at, updated_at) "
            . "VALUES (1, 20, 'Interdit', '2026-09-24T12:00:00Z', 'hash', "
            . "'2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );
    }

    public function testDeletingCategoryMakesItsFeedsUncategorized(): void
    {
        $this->migrator->migrate();
        $this->insertUsers();
        $this->pdo->exec(
            "INSERT INTO categories (id, user_id, name, created_at, updated_at) "
            . "VALUES (10, 1, 'Technique', '2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );
        $this->pdo->exec(
            "INSERT INTO feeds (id, user_id, category_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) "
            . "VALUES (20, 1, 10, 'Flux A', 'https://example.org/a', 1, 'never', "
            . "'2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );

        $this->pdo->exec('DELETE FROM categories WHERE id = 10 AND user_id = 1');
        $statement = $this->pdo->query('SELECT category_id FROM feeds WHERE id = 20');
        self::assertNotFalse($statement);
        $row = $statement->fetch();
        self::assertIsArray($row);
        self::assertNull($row['category_id']);
    }

    public function testArticleFtsStaysSynchronized(): void
    {
        $this->migrator->migrate();
        $this->insertUsers();
        $this->pdo->exec(
            "INSERT INTO feeds (id, user_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) "
            . "VALUES (20, 1, 'Flux A', 'https://example.org/a', 1, 'never', "
            . "'2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );
        $this->pdo->exec(
            "INSERT INTO articles (id, user_id, feed_id, title, discovered_at, deduplication_hash, created_at, updated_at) "
            . "VALUES (30, 1, 20, 'Premier titre', '2026-09-24T12:00:00Z', 'hash', "
            . "'2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );
        self::assertSame(1, $this->integerQuery(
            "SELECT COUNT(*) FROM articles_fts WHERE articles_fts MATCH 'Premier'"
        ));

        $this->pdo->exec("UPDATE articles SET title = 'Second titre' WHERE id = 30");
        self::assertSame(0, $this->integerQuery(
            "SELECT COUNT(*) FROM articles_fts WHERE articles_fts MATCH 'Premier'"
        ));
        self::assertSame(1, $this->integerQuery(
            "SELECT COUNT(*) FROM articles_fts WHERE articles_fts MATCH 'Second'"
        ));

        $this->pdo->exec("INSERT INTO articles_fts(articles_fts) VALUES ('delete-all')");
        self::assertSame(0, $this->integerQuery(
            "SELECT COUNT(*) FROM articles_fts WHERE articles_fts MATCH 'Second'"
        ));
        (new ArticleRepository($this->pdo))->rebuildSearchIndex();
        self::assertSame(1, $this->integerQuery(
            "SELECT COUNT(*) FROM articles_fts WHERE articles_fts MATCH 'Second'"
        ));

        $this->pdo->exec('DELETE FROM articles WHERE id = 30');
        self::assertSame(0, $this->integerQuery(
            "SELECT COUNT(*) FROM articles_fts WHERE articles_fts MATCH 'Second'"
        ));
    }

    private function insertUsers(): void
    {
        $hash = password_hash('password-for-tests', PASSWORD_DEFAULT);
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :created_at, :updated_at)'
        );
        foreach ([[1, 'alice'], [2, 'bob']] as [$id, $username]) {
            $statement->execute([
                'id' => $id,
                'username' => $username,
                'password_hash' => $hash,
                'created_at' => '2026-09-24T12:00:00Z',
                'updated_at' => '2026-09-24T12:00:00Z',
            ]);
        }
    }

    private function insertArticle(
        int $id,
        string $guid,
        string $url,
        string $title,
        string $publishedAt,
        int $isRead,
        int $isFavorite,
    ): void {
        $this->pdo->exec(
            "INSERT INTO articles (id, user_id, feed_id, guid, title, url, published_at, "
            . "discovered_at, is_read, is_favorite, deduplication_hash, created_at, updated_at) "
            . "VALUES ({$id}, 1, 1, '{$guid}', '" . $title . "', '{$url}', '{$publishedAt}', "
            . "'2026-09-24T12:00:00Z', {$isRead}, {$isFavorite}, 'hash-{$id}', "
            . "'2026-09-24T12:00:00Z', '2026-09-24T12:00:00Z')"
        );
    }

    /** @return list<int> */
    private function idList(): array
    {
        $statement = $this->pdo->query('SELECT id FROM articles ORDER BY id');
        self::assertNotFalse($statement);

        $ids = [];
        foreach ($statement->fetchAll(PDO::FETCH_COLUMN) as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }

    /** @return array<string, mixed> */
    private function row(int $id): array
    {
        $statement = $this->pdo->prepare('SELECT * FROM articles WHERE id = :id');
        $statement->execute(['id' => $id]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return $row;
    }

    private function integerQuery(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
