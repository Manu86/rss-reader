<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final readonly class RemoteActionAttemptRepository
{
    public function __construct(private PDO $pdo) {}

    public function countSince(int $userId, string $action, string $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM remote_action_attempts '
            . 'WHERE user_id = :user_id AND action = :action AND attempted_at >= :since'
        );
        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'since' => $since,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function add(int $userId, string $action, string $attemptedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO remote_action_attempts (user_id, action, attempted_at) '
            . 'VALUES (:user_id, :action, :attempted_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'action' => $action,
            'attempted_at' => $attemptedAt,
        ]);
    }

    public function pruneBefore(string $before): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM remote_action_attempts WHERE attempted_at < :before'
        );
        $statement->execute(['before' => $before]);
    }
}
