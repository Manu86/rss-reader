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
            ['001_initial_schema', '002_remote_action_attempts', '003_user_settings_theme', '004_article_tags'],
            $this->migrator->migrate(),
        );
        self::assertSame([], $this->migrator->migrate());
        self::assertSame(1, $this->integerQuery('PRAGMA foreign_keys'));
        self::assertSame(1, $this->integerQuery(
            "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'articles_fts'"
        ));
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

    private function integerQuery(string $sql): int
    {
        $statement = $this->pdo->query($sql);
        self::assertNotFalse($statement);

        return (int) $statement->fetchColumn();
    }
}
