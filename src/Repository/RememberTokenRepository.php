<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\RememberToken;
use PDO;

final readonly class RememberTokenRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(
        int $userId,
        string $selector,
        string $tokenHash,
        string $expiresAt,
        string $now,
    ): void {
        $statement = $this->pdo->prepare(
            'INSERT INTO user_remember_tokens (user_id, selector, token_hash, expires_at, created_at) '
            . 'VALUES (:user_id, :selector, :token_hash, :expires_at, :created_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'selector' => $selector,
            'token_hash' => $tokenHash,
            'expires_at' => $expiresAt,
            'created_at' => $now,
        ]);
    }

    public function findBySelector(string $selector): ?RememberToken
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, token_hash, expires_at FROM user_remember_tokens WHERE selector = :selector'
        );
        $statement->execute(['selector' => $selector]);
        $row = $statement->fetch();

        if (!is_array($row)) {
            return null;
        }

        return new RememberToken(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['token_hash'],
            (string) $row['expires_at'],
        );
    }

    public function deleteBySelector(string $selector): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_remember_tokens WHERE selector = :selector'
        );
        $statement->execute(['selector' => $selector]);
    }

    public function deleteAllForUser(int $userId): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_remember_tokens WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
    }

    public function deleteExpired(string $now): int
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM user_remember_tokens WHERE expires_at <= :now'
        );
        $statement->execute(['now' => $now]);

        return $statement->rowCount();
    }
}
