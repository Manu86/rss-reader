<?php

declare(strict_types=1);

namespace App\Repository;

use PDO;

final readonly class LoginAttemptRepository
{
    public function __construct(private PDO $pdo) {}

    public function countSince(string $identifierHash, string $addressHash, string $since): int
    {
        $statement = $this->pdo->prepare(
            'SELECT COUNT(*) FROM auth_login_attempts '
            . 'WHERE identifier_hash = :identifier_hash '
            . 'AND address_hash = :address_hash AND attempted_at >= :since'
        );
        $statement->execute([
            'identifier_hash' => $identifierHash,
            'address_hash' => $addressHash,
            'since' => $since,
        ]);

        return (int) $statement->fetchColumn();
    }

    public function add(string $identifierHash, string $addressHash, string $attemptedAt): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO auth_login_attempts (identifier_hash, address_hash, attempted_at) '
            . 'VALUES (:identifier_hash, :address_hash, :attempted_at)'
        );
        $statement->execute([
            'identifier_hash' => $identifierHash,
            'address_hash' => $addressHash,
            'attempted_at' => $attemptedAt,
        ]);
    }

    public function clear(string $identifierHash, string $addressHash): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM auth_login_attempts '
            . 'WHERE identifier_hash = :identifier_hash AND address_hash = :address_hash'
        );
        $statement->execute([
            'identifier_hash' => $identifierHash,
            'address_hash' => $addressHash,
        ]);
    }

    public function pruneBefore(string $before): void
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM auth_login_attempts WHERE attempted_at < :before'
        );
        $statement->execute(['before' => $before]);
    }
}
