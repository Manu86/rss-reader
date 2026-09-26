<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;
use Throwable;

final readonly class ArticleRetentionRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<array{user_id: int, image_path: string|null}> */
    public function deleteExpiredBatch(string $cutoff, int $limit): array
    {
        $this->pdo->exec('BEGIN IMMEDIATE TRANSACTION');
        try {
            $select = $this->pdo->prepare(
                'SELECT id, user_id, image_path FROM articles '
                . 'WHERE is_favorite = 0 AND COALESCE(published_at, discovered_at) < :cutoff '
                . 'ORDER BY COALESCE(published_at, discovered_at), id LIMIT :limit'
            );
            $select->bindValue(':cutoff', $cutoff, PDO::PARAM_STR);
            $select->bindValue(':limit', $limit, PDO::PARAM_INT);
            $select->execute();

            $delete = $this->pdo->prepare(
                'DELETE FROM articles WHERE id = :id AND user_id = :user_id '
                . 'AND is_favorite = 0 AND COALESCE(published_at, discovered_at) < :cutoff'
            );
            $deleted = [];
            while (($row = $select->fetch()) !== false) {
                if (!is_array($row)) {
                    continue;
                }
                $delete->execute([
                    'id' => (int) $row['id'],
                    'user_id' => (int) $row['user_id'],
                    'cutoff' => $cutoff,
                ]);
                if ($delete->rowCount() === 1) {
                    $deleted[] = [
                        'user_id' => (int) $row['user_id'],
                        'image_path' => $row['image_path'] === null ? null : (string) $row['image_path'],
                    ];
                }
            }
            $this->pdo->exec('COMMIT');

            return $deleted;
        } catch (Throwable $exception) {
            try {
                $this->pdo->exec('ROLLBACK');
            } catch (Throwable) {
                // Preserve the original cleanup failure.
            }
            throw $exception;
        }
    }
}
