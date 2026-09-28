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
            . 'discovered_at, summary, content, tags, deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :guid, :guid_hash, :title, :url, :author, '
            . ':published_at, :discovered_at, :summary, :content, :tags, :deduplication_hash, '
            . ':created_at, :updated_at)'
        );
        $missingImage = $this->pdo->prepare(
            'SELECT id FROM articles WHERE user_id = :user_id AND feed_id = :feed_id '
            . 'AND deduplication_hash = :deduplication_hash AND image_path IS NULL '
            . 'AND (image_metadata_checked_at IS NULL OR :has_feed_image = 1)'
        );
        $updateExisting = $this->pdo->prepare(
            'UPDATE articles SET title = :title, url = :url, author = :author, '
            . 'published_at = :published_at, summary = :summary, content = :content, '
            . 'tags = :tags, updated_at = :updated_at WHERE user_id = :user_id AND feed_id = :feed_id '
            . 'AND deduplication_hash = :deduplication_hash'
        );
        // Un flux peut republier un item avec un nouveau GUID. L'identite de niveau 1
        // (GUID) primerait alors, alors que l'URL et le titre designent le meme article.
        // Le titre est exige pour ne jamais fusionner des articles distincts qui
        // partagent une URL generique, comme la racine d'un site. Le meme jour de
        // publication est egalement exige : certains flux reprogramment le meme episode
        // chaque semaine sous une URL et un titre identiques, et ces rediffusions sont
        // des articles distincts.
        $republished = $this->pdo->prepare(
            'SELECT id, CASE WHEN image_path IS NULL '
            . 'AND (image_metadata_checked_at IS NULL OR :has_feed_image = 1) THEN 1 ELSE 0 END '
            . 'AS wants_media FROM articles WHERE user_id = :user_id AND feed_id = :feed_id '
            . 'AND url = :url AND title = :title AND deduplication_hash <> :deduplication_hash '
            . 'AND date(published_at) = date(:published_at) '
            . 'ORDER BY id LIMIT 1'
        );
        $updateById = $this->pdo->prepare(
            'UPDATE articles SET title = :title, url = :url, author = :author, '
            . 'published_at = :published_at, summary = :summary, content = :content, '
            . 'tags = :tags, updated_at = :updated_at WHERE id = :id AND user_id = :user_id'
        );
        $inserted = 0;
        /** @var array<int, array{id: int, image_url: string|null, article_url: string|null, metadata_fallback: bool}> $mediaCandidates */
        $mediaCandidates = [];
        foreach ($articles as $article) {
            $existingId = null;
            $isRepublish = false;
            $wantsMedia = false;
            if ($article->url !== null) {
                $republished->execute([
                    'user_id' => $userId,
                    'feed_id' => $feedId,
                    'url' => $article->url,
                    'title' => $article->title,
                    'deduplication_hash' => $article->deduplicationHash,
                    'has_feed_image' => $article->imageUrl !== null ? 1 : 0,
                    'published_at' => $article->publishedAt,
                ]);
                $row = $republished->fetch(PDO::FETCH_ASSOC);
                if (is_array($row)) {
                    $existingId = (int) $row['id'];
                    $isRepublish = true;
                    $wantsMedia = (int) $row['wants_media'] === 1;
                }
            }
            if ($existingId === null) {
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
                    'tags' => $article->tags === [] ? null : json_encode(array_values($article->tags), JSON_UNESCAPED_UNICODE),
                    'deduplication_hash' => $article->deduplicationHash,
                    'created_at' => $discoveredAt,
                    'updated_at' => $discoveredAt,
                ]);
                if ($statement->rowCount() === 1) {
                    ++$inserted;
                    if ($article->imageUrl !== null || $article->url !== null) {
                        $articleId = (int) $this->pdo->lastInsertId();
                        $mediaCandidates[$articleId] = [
                            'id' => $articleId,
                            'image_url' => $article->imageUrl,
                            'article_url' => $article->url,
                            'metadata_fallback' => $article->imageUrl === null,
                        ];
                    }

                    continue;
                }
                if ($article->imageUrl !== null || $article->url !== null) {
                    $missingImage->execute([
                        'user_id' => $userId,
                        'feed_id' => $feedId,
                        'deduplication_hash' => $article->deduplicationHash,
                        'has_feed_image' => $article->imageUrl !== null ? 1 : 0,
                    ]);
                    $articleId = $missingImage->fetchColumn();
                    if ($articleId !== false) {
                        $existingId = (int) $articleId;
                        $wantsMedia = true;
                    }
                }
            }
            $updateParameters = [
                'title' => $article->title,
                'url' => $article->url,
                'author' => $article->author,
                'published_at' => $article->publishedAt,
                'summary' => $article->summary,
                'content' => $article->content,
                'tags' => $article->tags === [] ? null : json_encode(array_values($article->tags), JSON_UNESCAPED_UNICODE),
                'updated_at' => $discoveredAt,
            ];
            if ($existingId !== null && $wantsMedia
                && ($article->imageUrl !== null || $article->url !== null)) {
                $mediaCandidates[$existingId] = [
                    'id' => $existingId,
                    'image_url' => $article->imageUrl,
                    'article_url' => $article->url,
                    'metadata_fallback' => $article->imageUrl === null,
                ];
            }
            if ($isRepublish) {
                $updateById->execute($updateParameters + [
                    'id' => $existingId,
                    'user_id' => $userId,
                ]);

                continue;
            }
            $updateExisting->execute($updateParameters + [
                'user_id' => $userId,
                'feed_id' => $feedId,
                'deduplication_hash' => $article->deduplicationHash,
            ]);
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

    public function markImageMetadataChecked(int $articleId, int $userId, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE articles SET image_metadata_checked_at = :checked_at, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id AND image_metadata_checked_at IS NULL'
        );
        $statement->execute([
            'checked_at' => $now,
            'updated_at' => $now,
            'id' => $articleId,
            'user_id' => $userId,
        ]);
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

    /**
     * Unread articles of the user matching the FTS query, with their FTS
     * relevance (`-bm25`, so a higher value means a closer match).
     *
     * @return list<array{article: Article, relevance: float}>
     */
    public function searchUnreadOwnedWithRelevance(
        int $userId,
        ?int $categoryId,
        bool $uncategorized,
        string $ftsQuery,
        int $perFeedLimit,
        int $limit,
    ): array {
        [$where, $parameters] = $this->listWhere(
            $userId,
            new ArticleListCriteria('unread', $categoryId, $uncategorized, null, 1, $limit),
        );
        $statement = $this->pdo->prepare(
            'WITH matches AS ('
            . 'SELECT articles_fts.rowid AS article_id, a.feed_id, '
            . 'COALESCE(a.published_at, a.discovered_at) AS article_date, '
            . 'bm25(articles_fts, 1.0, 1.0, 1.0, 0.1) AS relevance FROM articles_fts '
            . 'INNER JOIN articles a ON a.id = articles_fts.rowid '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'WHERE articles_fts MATCH :search AND a.is_favorite = 0 AND ' . $where
            . '), diversified AS ('
            . 'SELECT article_id, relevance, ROW_NUMBER() OVER ('
            . 'PARTITION BY feed_id ORDER BY relevance, article_date DESC, article_id DESC'
            . ') AS feed_rank FROM matches'
            . ') '
            . $this->articleColumns() . ', diversified.relevance AS relevance FROM diversified '
            . 'INNER JOIN articles a ON a.id = diversified.article_id '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'LEFT JOIN categories c ON c.id = f.category_id AND c.user_id = f.user_id '
            . 'WHERE diversified.feed_rank <= :per_feed_limit '
            . 'ORDER BY diversified.relevance, COALESCE(a.published_at, a.discovered_at) DESC, a.id DESC '
            . 'LIMIT :limit'
        );
        $statement->bindValue(':search', $ftsQuery, PDO::PARAM_STR);
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, PDO::PARAM_INT);
        }
        $statement->bindValue(':per_feed_limit', $perFeedLimit, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $matches = [];
        while (($row = $statement->fetch()) !== false) {
            $article = $this->hydrate($row);
            if ($article !== null) {
                $matches[] = ['article' => $article, 'relevance' => -1.0 * (float) $row['relevance']];
            }
        }

        return $matches;
    }

    /**
     * Unread non-favorite articles sharing a tag or a category with the
     * user's favorite signals. The per-feed window prevents a prolific feed
     * from exhausting the bounded candidate set before scoring.
     *
     * @param list<string> $tags normalized lowercase tags
     * @param list<int> $categoryIds
     * @return list<Article>
     */
    public function listUnreadOwnedByAffinity(
        int $userId,
        ?int $categoryId,
        bool $uncategorized,
        array $tags,
        array $categoryIds,
        int $perFeedLimit,
        int $limit,
    ): array {
        if ($tags === [] && $categoryIds === []) {
            return [];
        }

        [$where, $parameters] = $this->listWhere(
            $userId,
            new ArticleListCriteria('unread', $categoryId, $uncategorized, null, 1, $limit),
        );
        $affinities = [];
        foreach (array_values($categoryIds) as $index => $favoriteCategoryId) {
            $name = 'favorite_category_' . $index;
            $affinities[] = 'f.category_id = :' . $name;
            $parameters[$name] = $favoriteCategoryId;
        }
        if ($tags !== []) {
            $tagParameters = [];
            foreach (array_values($tags) as $index => $tag) {
                $name = 'favorite_tag_' . $index;
                $tagParameters[] = ':' . $name;
                $parameters[$name] = $tag;
            }
            $affinities[] = 'EXISTS (SELECT 1 FROM json_each('
                . "CASE WHEN json_valid(a.tags) THEN a.tags ELSE '[]' END"
                . ') AS article_tag WHERE lower(trim(CAST(article_tag.value AS TEXT))) IN ('
                . implode(', ', $tagParameters) . '))';
        }

        $statement = $this->pdo->prepare(
            'WITH affinity_candidates AS ('
            . 'SELECT a.id AS article_id, a.feed_id, '
            . 'ROW_NUMBER() OVER (PARTITION BY a.feed_id ORDER BY '
            . 'COALESCE(a.published_at, a.discovered_at) DESC, a.id DESC) AS feed_rank '
            . 'FROM articles a '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'WHERE a.is_favorite = 0 AND ' . $where
            . ' AND (' . implode(' OR ', $affinities) . ')'
            . ') '
            . $this->articleColumns() . ' FROM affinity_candidates '
            . 'INNER JOIN articles a ON a.id = affinity_candidates.article_id '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'LEFT JOIN categories c ON c.id = f.category_id AND c.user_id = f.user_id '
            . 'WHERE affinity_candidates.feed_rank <= :per_feed_limit '
            . 'ORDER BY COALESCE(a.published_at, a.discovered_at) DESC, a.id DESC '
            . 'LIMIT :limit'
        );
        foreach ($parameters as $name => $value) {
            $statement->bindValue(':' . $name, $value, is_int($value) ? PDO::PARAM_INT : PDO::PARAM_STR);
        }
        $statement->bindValue(':per_feed_limit', $perFeedLimit, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
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
            if ($favorite) {
                $assignments[] = 'favorited_at = COALESCE(favorited_at, :favorited_at_now)';
                $parameters['favorited_at_now'] = $now;
            } else {
                $assignments[] = 'favorited_at = NULL';
            }
        }
        $statement = $this->pdo->prepare(
            'UPDATE articles SET ' . implode(', ', $assignments)
            . ' WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute($parameters);

        return $this->findOwned($articleId, $userId);
    }

    /**
     * @return list<array{id: int, title: string, tags: list<string>, category_id: int|null}>
     */
    public function listFavoriteSignals(int $userId, int $limit): array
    {
        $statement = $this->pdo->prepare(
            'SELECT a.id, a.title, a.tags, f.category_id FROM articles a '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'WHERE a.user_id = :user_id AND a.is_favorite = 1 '
            . 'ORDER BY a.favorited_at DESC, a.id DESC LIMIT :limit'
        );
        $statement->bindValue(':user_id', $userId, PDO::PARAM_INT);
        $statement->bindValue(':limit', $limit, PDO::PARAM_INT);
        $statement->execute();

        $signals = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            $signals[] = [
                'id' => (int) $row['id'],
                'title' => (string) $row['title'],
                'tags' => $this->decodeTags($row['tags'] ?? null),
                'category_id' => $row['category_id'] === null ? null : (int) $row['category_id'],
            ];
        }

        return $signals;
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
            . 'a.tags, a.image_path, a.is_read, a.is_favorite';
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
            $this->decodeTags($row['tags'] ?? null),
            $row['image_path'] !== null,
            (bool) $row['is_read'],
            (bool) $row['is_favorite'],
        );
    }

    /** @return list<string> */
    private function decodeTags(mixed $value): array
    {
        if ($value === null || !is_string($value) || $value === '') {
            return [];
        }
        try {
            $decoded = json_decode($value, true, 3, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return [];
        }
        if (!is_array($decoded)) {
            return [];
        }
        $tags = [];
        foreach ($decoded as $tag) {
            if (!is_string($tag)) {
                continue;
            }
            $text = trim($tag);
            if ($text === '' || strlen($text) > 100 || in_array($text, $tags, true)) {
                continue;
            }
            $tags[] = $text;
            if (count($tags) >= 10) {
                break;
            }
        }

        return $tags;
    }
}
