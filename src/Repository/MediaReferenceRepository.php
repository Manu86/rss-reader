<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final readonly class MediaReferenceRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<string> */
    public function listForOwnedFeed(int $feedId, int $userId): array
    {
        $paths = [];
        $feed = $this->pdo->prepare(
            'SELECT favicon_path FROM feeds WHERE id = :id AND user_id = :user_id'
        );
        $feed->execute(['id' => $feedId, 'user_id' => $userId]);
        $favicon = $feed->fetchColumn();
        if (is_string($favicon) && $favicon !== '') {
            $paths[] = $favicon;
        }

        $articles = $this->pdo->prepare(
            'SELECT image_path FROM articles '
            . 'WHERE feed_id = :feed_id AND user_id = :user_id AND image_path IS NOT NULL'
        );
        $articles->execute(['feed_id' => $feedId, 'user_id' => $userId]);
        while (($path = $articles->fetchColumn()) !== false) {
            if (is_string($path) && $path !== '') {
                $paths[] = $path;
            }
        }

        return array_values(array_unique($paths));
    }

    public function isReferenced(int $userId, string $path): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT EXISTS('
            . 'SELECT 1 FROM feeds WHERE user_id = :feed_user_id AND favicon_path = :feed_path '
            . 'UNION ALL '
            . 'SELECT 1 FROM articles WHERE user_id = :article_user_id AND image_path = :article_path'
            . ')'
        );
        $statement->execute([
            'feed_user_id' => $userId,
            'feed_path' => $path,
            'article_user_id' => $userId,
            'article_path' => $path,
        ]);

        return (int) $statement->fetchColumn() === 1;
    }
}
