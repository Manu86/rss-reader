<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Feed;
use PDO;
use Throwable;

final readonly class FeedRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<Feed> */
    public function listOwned(int $userId, ?int $categoryId, ?bool $active): array
    {
        $sql = 'SELECT * FROM feeds WHERE user_id = :user_id';
        $parameters = ['user_id' => $userId];
        if ($categoryId !== null) {
            $sql .= ' AND category_id = :category_id';
            $parameters['category_id'] = $categoryId;
        }
        if ($active !== null) {
            $sql .= ' AND is_active = :is_active';
            $parameters['is_active'] = $active ? 1 : 0;
        }
        $sql .= ' ORDER BY name COLLATE NOCASE, id';

        $statement = $this->pdo->prepare($sql);
        $statement->execute($parameters);
        $feeds = [];
        while (($row = $statement->fetch()) !== false) {
            $feed = $this->hydrate($row);
            if ($feed !== null) {
                $feeds[] = $feed;
            }
        }

        return $feeds;
    }

    /** @return list<Feed> */
    public function listActiveForEnabledUsers(): array
    {
        $statement = $this->pdo->query(
            'SELECT feeds.* FROM feeds '
            . 'INNER JOIN users ON users.id = feeds.user_id '
            . 'WHERE feeds.is_active = 1 AND users.is_active = 1 '
            . 'ORDER BY feeds.user_id, feeds.id'
        );
        if ($statement === false) {
            return [];
        }

        $feeds = [];
        while (($row = $statement->fetch()) !== false) {
            $feed = $this->hydrate($row);
            if ($feed !== null) {
                $feeds[] = $feed;
            }
        }

        return $feeds;
    }

    public function findOwned(int $feedId, int $userId): ?Feed
    {
        $statement = $this->pdo->prepare(
            'SELECT * FROM feeds WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $feedId, 'user_id' => $userId]);

        return $this->hydrate($statement->fetch());
    }

    public function existsOwnedUrl(int $userId, string $feedUrl): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM feeds WHERE user_id = :user_id AND feed_url = :feed_url'
        );
        $statement->execute(['user_id' => $userId, 'feed_url' => $feedUrl]);

        return $statement->fetchColumn() !== false;
    }

    public function create(
        int $userId,
        ?int $categoryId,
        string $name,
        string $feedUrl,
        string $now,
    ): Feed {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(user_id, category_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:user_id, :category_id, :name, :feed_url, 1, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => $name,
            'feed_url' => $feedUrl,
            'status' => 'never',
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $feed = $this->findOwned((int) $this->pdo->lastInsertId(), $userId);
        if ($feed === null) {
            throw new \RuntimeException('L’abonnement créé est introuvable.');
        }

        return $feed;
    }

    public function createImported(
        int $userId,
        ?int $categoryId,
        string $name,
        string $feedUrl,
        ?string $siteUrl,
        ?string $etag,
        ?string $lastModified,
        string $now,
    ): Feed {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(user_id, category_id, name, feed_url, site_url, is_active, last_fetch_attempt_at, '
            . 'last_successful_fetch_at, last_fetch_status, etag, last_modified, created_at, updated_at) '
            . 'VALUES (:user_id, :category_id, :name, :feed_url, :site_url, 1, :attempted_at, '
            . ':successful_at, :status, :etag, :last_modified, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'category_id' => $categoryId,
            'name' => $name,
            'feed_url' => $feedUrl,
            'site_url' => $siteUrl,
            'attempted_at' => $now,
            'successful_at' => $now,
            'status' => 'success',
            'etag' => $etag,
            'last_modified' => $lastModified,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $feed = $this->findOwned((int) $this->pdo->lastInsertId(), $userId);
        if ($feed === null) {
            throw new \RuntimeException('L’abonnement créé est introuvable.');
        }

        return $feed;
    }

    public function markAttempt(Feed $feed, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE feeds SET last_fetch_attempt_at = :attempted_at, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'attempted_at' => $now,
            'updated_at' => $now,
            'id' => $feed->id,
            'user_id' => $feed->userId,
        ]);
    }

    public function markFailure(Feed $feed, string $message, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE feeds SET last_fetch_attempt_at = :attempted_at, last_fetch_status = :status, '
            . 'last_fetch_error = :error, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'attempted_at' => $now,
            'status' => 'error',
            'error' => mb_substr($message, 0, 500, 'UTF-8'),
            'updated_at' => $now,
            'id' => $feed->id,
            'user_id' => $feed->userId,
        ]);
    }

    public function markSuccess(
        Feed $feed,
        ?string $siteUrl,
        ?string $etag,
        ?string $lastModified,
        string $now,
    ): Feed {
        $statement = $this->pdo->prepare(
            'UPDATE feeds SET site_url = COALESCE(:site_url, site_url), '
            . 'last_fetch_attempt_at = :attempted_at, last_successful_fetch_at = :successful_at, '
            . 'last_article_at = (SELECT MAX(COALESCE(published_at, discovered_at)) '
            . 'FROM articles WHERE feed_id = :article_feed_id AND user_id = :article_user_id), '
            . 'last_fetch_status = :status, last_fetch_error = NULL, etag = :etag, '
            . 'last_modified = :last_modified, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'site_url' => $siteUrl,
            'attempted_at' => $now,
            'successful_at' => $now,
            'article_feed_id' => $feed->id,
            'article_user_id' => $feed->userId,
            'status' => 'success',
            'etag' => $etag,
            'last_modified' => $lastModified,
            'updated_at' => $now,
            'id' => $feed->id,
            'user_id' => $feed->userId,
        ]);

        $updated = $this->findOwned($feed->id, $feed->userId);
        if ($updated === null) {
            throw new \RuntimeException('L’abonnement synchronisé est introuvable.');
        }

        return $updated;
    }

    public function setFaviconPath(Feed $feed, string $path, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE feeds SET favicon_path = :favicon_path, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id AND favicon_path IS NULL'
        );
        $statement->execute([
            'favicon_path' => $path,
            'updated_at' => $now,
            'id' => $feed->id,
            'user_id' => $feed->userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function update(Feed $feed, string $name, ?int $categoryId, bool $active, string $now): Feed
    {
        $statement = $this->pdo->prepare(
            'UPDATE feeds SET name = :name, category_id = :category_id, '
            . 'is_active = :is_active, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'name' => $name,
            'category_id' => $categoryId,
            'is_active' => $active ? 1 : 0,
            'updated_at' => $now,
            'id' => $feed->id,
            'user_id' => $feed->userId,
        ]);

        $updated = $this->findOwned($feed->id, $feed->userId);
        if ($updated === null) {
            throw new \RuntimeException('L’abonnement modifié est introuvable.');
        }

        return $updated;
    }

    public function deleteOwned(int $feedId, int $userId): bool
    {
        $this->pdo->beginTransaction();
        try {
            $articles = $this->pdo->prepare(
                'DELETE FROM articles WHERE feed_id = :feed_id AND user_id = :user_id'
            );
            $articles->execute(['feed_id' => $feedId, 'user_id' => $userId]);

            $feed = $this->pdo->prepare(
                'DELETE FROM feeds WHERE id = :id AND user_id = :user_id'
            );
            $feed->execute(['id' => $feedId, 'user_id' => $userId]);
            if ($feed->rowCount() !== 1) {
                $this->pdo->rollBack();

                return false;
            }

            $this->pdo->commit();

            return true;
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function hydrate(mixed $row): ?Feed
    {
        if (!is_array($row)) {
            return null;
        }

        return new Feed(
            (int) $row['id'],
            (int) $row['user_id'],
            $row['category_id'] === null ? null : (int) $row['category_id'],
            (string) $row['name'],
            (string) $row['feed_url'],
            $row['site_url'] === null ? null : (string) $row['site_url'],
            $row['favicon_path'] === null ? null : (string) $row['favicon_path'],
            (bool) $row['is_active'],
            $row['last_fetch_attempt_at'] === null ? null : (string) $row['last_fetch_attempt_at'],
            $row['last_successful_fetch_at'] === null ? null : (string) $row['last_successful_fetch_at'],
            $row['last_article_at'] === null ? null : (string) $row['last_article_at'],
            $row['last_fetch_status'] === null ? null : (string) $row['last_fetch_status'],
            $row['last_fetch_error'] === null ? null : (string) $row['last_fetch_error'],
            $row['etag'] === null ? null : (string) $row['etag'],
            $row['last_modified'] === null ? null : (string) $row['last_modified'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
