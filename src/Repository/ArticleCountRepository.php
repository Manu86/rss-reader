<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final readonly class ArticleCountRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return array{all: int, unread: int, read: int, favorites: int, categories: list<array{category_id: int, unread: int}>, uncategorized: int, feeds: list<array{feed_id: int, unread: int}>} */
    public function forUser(int $userId): array
    {
        $main = $this->pdo->prepare(
            'SELECT COUNT(*) AS total, '
            . 'COALESCE(SUM(CASE WHEN is_read = 0 THEN 1 ELSE 0 END), 0) AS unread, '
            . 'COALESCE(SUM(CASE WHEN is_read = 1 THEN 1 ELSE 0 END), 0) AS read_count, '
            . 'COALESCE(SUM(CASE WHEN is_favorite = 1 THEN 1 ELSE 0 END), 0) AS favorites '
            . 'FROM articles WHERE user_id = :user_id'
        );
        $main->execute(['user_id' => $userId]);
        $mainRow = $main->fetch();
        if (!is_array($mainRow)) {
            $mainRow = ['total' => 0, 'unread' => 0, 'read_count' => 0, 'favorites' => 0];
        }

        $categoryStatement = $this->pdo->prepare(
            'SELECT c.id AS category_id, '
            . 'COALESCE(SUM(CASE WHEN a.is_read = 0 THEN 1 ELSE 0 END), 0) AS unread '
            . 'FROM categories c '
            . 'LEFT JOIN feeds f ON f.category_id = c.id AND f.user_id = c.user_id '
            . 'LEFT JOIN articles a ON a.feed_id = f.id AND a.user_id = c.user_id '
            . 'WHERE c.user_id = :user_id GROUP BY c.id ORDER BY c.name COLLATE NOCASE, c.id'
        );
        $categoryStatement->execute(['user_id' => $userId]);
        $categories = [];
        while (($row = $categoryStatement->fetch()) !== false) {
            if (is_array($row)) {
                $categories[] = [
                    'category_id' => (int) $row['category_id'],
                    'unread' => (int) $row['unread'],
                ];
            }
        }

        $uncategorizedStatement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM articles a '
            . 'INNER JOIN feeds f ON f.id = a.feed_id AND f.user_id = a.user_id '
            . 'WHERE a.user_id = :user_id AND a.is_read = 0 AND f.category_id IS NULL'
        );
        $uncategorizedStatement->execute(['user_id' => $userId]);

        $feedStatement = $this->pdo->prepare(
            'SELECT f.id AS feed_id, '
            . 'COALESCE(SUM(CASE WHEN a.is_read = 0 THEN 1 ELSE 0 END), 0) AS unread '
            . 'FROM feeds f LEFT JOIN articles a ON a.feed_id = f.id AND a.user_id = f.user_id '
            . 'WHERE f.user_id = :user_id GROUP BY f.id ORDER BY f.name COLLATE NOCASE, f.id'
        );
        $feedStatement->execute(['user_id' => $userId]);
        $feeds = [];
        while (($row = $feedStatement->fetch()) !== false) {
            if (is_array($row)) {
                $feeds[] = [
                    'feed_id' => (int) $row['feed_id'],
                    'unread' => (int) $row['unread'],
                ];
            }
        }

        return [
            'all' => (int) $mainRow['total'],
            'unread' => (int) $mainRow['unread'],
            'read' => (int) $mainRow['read_count'],
            'favorites' => (int) $mainRow['favorites'],
            'categories' => $categories,
            'uncategorized' => (int) $uncategorizedStatement->fetchColumn(),
            'feeds' => $feeds,
        ];
    }
}
