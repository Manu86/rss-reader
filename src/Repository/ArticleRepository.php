<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Article;
use App\Model\ArticleInsertResult;
use App\Model\ArticleListCriteria;
use App\Model\ParsedArticle;
use PDO;

final readonly class ArticleRepository
{
    public function __construct(private PDO $pdo) {}

    /** @param list<ParsedArticle> $articles */
    public function insertNew(int $userId, int $feedId, array $articles, string $discoveredAt): ArticleInsertResult
    {
        $statement = $this->pdo->prepare(
            'INSERT OR IGNORE INTO articles '
            . '(user_id, feed_id, guid, guid_hash, title, url, author, published_at, '
            . 'discovered_at, summary, content, deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :guid, :guid_hash, :title, :url, :author, '
            . ':published_at, :discovered_at, :summary, :content, :deduplication_hash, '
            . ':created_at, :updated_at)'
        );
        $missingImage = $this->pdo->prepare(
            'SELECT id FROM articles WHERE user_id = :user_id AND feed_id = :feed_id '
            . 'AND deduplication_hash = :deduplication_hash AND image_path IS NULL'
        );
        $updateExisting = $this->pdo->prepare(
            'UPDATE articles SET title = :title, url = :url, author = :author, '
            . 'published_at = :published_at, summary = :summary, content = :content, '
            . 'updated_at = :updated_at WHERE user_id = :user_id AND feed_id = :feed_id '
            . 'AND deduplication_hash = :deduplication_hash'
        );
        $inserted = 0;
        /** @var array<int, array{id: int, image_url: string}> $mediaCandidates */
        $mediaCandidates = [];
        foreach ($articles as $article) {
            $statement->execute([
                'user_id' => $userId,
                'feed_id' => $feedId,
                'guid' => $article->guid,
                'guid_hash' => $article->guidHash,
                'title' => $article->title,
                'url' => $article->url,
                'author' => $article->author,
                'published_at' => $article->publishedAt,
                'discovered_at' => $discoveredAt,
                'summary' => $article->summary,
                'content' => $article->content,
                'deduplication_hash' => $article->deduplicationHash,
                'created_at' => $discoveredAt,
                'updated_at' => $discoveredAt,
            ]);
            if ($statement->rowCount() === 1) {
                ++$inserted;
                if ($article->imageUrl !== null) {
                    $articleId = (int) $this->pdo->lastInsertId();
                    $mediaCandidates[$articleId] = [
                        'id' => $articleId,
                        'image_url' => $article->imageUrl,
                    ];
                }
            } elseif ($article->imageUrl !== null) {
                $missingImage->execute([
                    'user_id' => $userId,
                    'feed_id' => $feedId,
                    'deduplication_hash' => $article->deduplicationHash,
                ]);
                $articleId = $missingImage->fetchColumn();
                if ($articleId !== false) {
                    $mediaCandidates[(int) $articleId] = [
                        'id' => (int) $articleId,
                        'image_url' => $article->imageUrl,
                    ];
                }
            }
            if ($statement->rowCount() !== 1) {
                $updateExisting->execute([
                    'title' => $article->title,
                    'url' => $article->url,
                    'author' => $article->author,
                    'published_at' => $article->publishedAt,
                    'summary' => $article->summary,
                    'content' => $article->content,
                    'updated_at' => $discoveredAt,
                    'user_id' => $userId,
                    'feed_id' => $feedId,
                    'deduplication_hash' => $article->deduplicationHash,
                ]);
            }
        }

        return new ArticleInsertResult($inserted, array_values($mediaCandidates));
    }

    public function setImagePath(int $articleId, int $userId, string $path, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE articles SET image_path = :image_path, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id AND image_path IS NULL'
        );
        $statement->execute([
            'image_path' => $path,
            'updated_at' => $now,
            'id' => $articleId,
            'user_id' => $userId,
        ]);

        return $statement->rowCount() === 1;
    }

    public function findImagePathOwned(int $articleId, int $userId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT image_path FROM articles WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $articleId, 'user_id' => $userId]);
        $path = $statement->fetchColumn();

        return is_string($path) && $path !== '' ? $path : null;
    }

    /** @return list<Article> */
    public function listOwned(int $userId, ArticleListCriteria $criteria): array
    {
        [$where, $parameters] = $this->listWhere($userId, $criteria);
        $statement = $this->pdo->prepare(
            $this->articleSelect() . ' WHERE ' . $where
            . ' ORDER BY COALESCE(a.published_at, a.discovered_at) DESC, a.id DESC'
            . ' LIMIT :limit OFFSET :offset'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $criteria->perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($criteria->page - 1) * $criteria->perPage, PDO::PARAM_INT);
        $statement->execute();

        $articles = [];
        while (($row = $statement->fetch()) !== false) {
            $article = $this->hydrate($row);
            if ($article !== null) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    public function countOwned(int $userId, ArticleListCriteria $criteria): int
    {
        [$where, $parameters] = $this->listWhere($userId, $criteria);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM articles a INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'WHERE ' . $where
        );
        $statement->execute($parameters);

        return (int) $statement->fetchColumn();
    }

    /** @return list<Article> */
    public function searchOwned(int $userId, ArticleListCriteria $criteria, string $ftsQuery): array
    {
        [$where, $parameters] = $this->listWhere($userId, $criteria);
        $statement = $this->pdo->prepare(
            $this->articleColumns() . ' FROM articles_fts '
            . 'INNER JOIN articles a ON a.id = articles_fts.rowid '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'LEFT JOIN categories c ON c.id = f.category_id AND c.user_id = f.user_id '
            . 'WHERE articles_fts MATCH :search AND ' . $where
            . ' ORDER BY bm25(articles_fts), COALESCE(a.published_at, a.discovered_at) DESC, a.id DESC'
            . ' LIMIT :limit OFFSET :offset'
        );
        $statement->bindValue(':search', $ftsQuery, PDO::PARAM_STR);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':limit', $criteria->perPage, PDO::PARAM_INT);
        $statement->bindValue(':offset', ($criteria->page - 1) * $criteria->perPage, PDO::PARAM_INT);
        $statement->execute();

        $articles = [];
        while (($row = $statement->fetch()) !== false) {
            $article = $this->hydrate($row);
            if ($article !== null) {
                $articles[] = $article;
            }
        }

        return $articles;
    }

    public function countSearchOwned(int $userId, ArticleListCriteria $criteria, string $ftsQuery): int
    {
        [$where, $parameters] = $this->listWhere($userId, $criteria);
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM articles_fts '
            . 'INNER JOIN articles a ON a.id = articles_fts.rowid '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'WHERE articles_fts MATCH :search AND ' . $where
        );
        $statement->bindValue(':search', $ftsQuery, PDO::PARAM_STR);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
        $statement->execute();

        return (int) $statement->fetchColumn();
    }

    public function rebuildSearchIndex(): void
    {
        $this->pdo->exec("INSERT INTO articles_fts(articles_fts) VALUES ('rebuild')");
    }

    public function findOwned(int $articleId, int $userId): ?Article
    {
        $statement = $this->pdo->prepare(
            $this->articleSelect() . ' WHERE a.id = :id AND a.user_id = :user_id'
        );
        $statement->execute(['id' => $articleId, 'user_id' => $userId]);

        return $this->hydrate($statement->fetch());
    }

    public function updateStateOwned(
        int $articleId,
        int $userId,
        ?bool $read,
        ?bool $favorite,
        string $now,
    ): ?Article {
        $assignments = ['updated_at = :updated_at'];
        $parameters = [
            'updated_at' => $now,
            'id' => $articleId,
            'user_id' => $userId,
        ];
        if ($read !== null) {
            $assignments[] = 'is_read = :is_read';
            $parameters['is_read'] = $read ? 1 : 0;
        }
        if ($favorite !== null) {
            $assignments[] = 'is_favorite = :is_favorite';
            $parameters['is_favorite'] = $favorite ? 1 : 0;
        }
        $statement = $this->pdo->prepare(
            'UPDATE articles SET ' . implode(', ', $assignments)
            . ' WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($parameters);

        return $this->findOwned($articleId, $userId);
    }

    /** @return array{string, array<string, int>} */
    private function listWhere(int $userId, ArticleListCriteria $criteria): array
    {
        $parts = ['a.user_id = :user_id'];
        $parameters = ['user_id' => $userId];
        $state = match ($criteria->filter) {
            'unread' => 'a.is_read = 0',
            'read' => 'a.is_read = 1',
            'favorites' => 'a.is_favorite = 1',
            default => null,
        };
        if ($state !== null) {
            $parts[] = $state;
        }
        if ($criteria->categoryId !== null) {
            $parts[] = 'f.category_id = :category_id';
            $parameters['category_id'] = $criteria->categoryId;
        } elseif ($criteria->uncategorized) {
            $parts[] = 'f.category_id IS NULL';
        }
        if ($criteria->feedId !== null) {
            $parts[] = 'a.feed_id = :feed_id';
            $parameters['feed_id'] = $criteria->feedId;
        }

        return [implode(' AND ', $parts), $parameters];
    }

    private function articleSelect(): string
    {
        return $this->articleColumns() . ' FROM articles a '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'LEFT JOIN categories c ON c.id = f.category_id AND c.user_id = f.user_id';
    }

    private function articleColumns(): string
    {
        return 'SELECT a.id, a.feed_id, f.name AS feed_name, f.favicon_path AS feed_favicon_path, '
            . 'c.id AS category_id, c.name AS category_name, '
            . 'a.title, a.url, a.author, a.published_at, a.discovered_at, a.summary, a.content, '
            . 'a.image_path, a.is_read, a.is_favorite';
    }

    private function hydrate(mixed $row): ?Article
    {
        if (!is_array($row)) {
            return null;
        }

        return new Article(
            (int) $row['id'],
            (int) $row['feed_id'],
            (string) $row['feed_name'],
            $row['feed_favicon_path'] !== null,
            $row['category_id'] === null ? null : (int) $row['category_id'],
            $row['category_name'] === null ? null : (string) $row['category_name'],
            (string) $row['title'],
            $row['url'] === null ? null : (string) $row['url'],
            $row['author'] === null ? null : (string) $row['author'],
            $row['published_at'] === null ? null : (string) $row['published_at'],
            (string) $row['discovered_at'],
            $row['summary'] === null ? null : (string) $row['summary'],
            $row['content'] === null ? null : (string) $row['content'],
            $row['image_path'] !== null,
            (bool) $row['is_read'],
            (bool) $row['is_favorite'],
        );
    }
}
