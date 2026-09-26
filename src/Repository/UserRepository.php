<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\User;
use PDO;
use Throwable;

final readonly class UserRepository
{
    public function __construct(private PDO $pdo) {}

    public function create(string $username, string $passwordHash, string $now): User
    {
        $this->pdo->beginTransaction();
        try {
            $statement = $this->pdo->prepare(
                'INSERT INTO users (username, password_hash, created_at, updated_at) '
                . 'VALUES (:username, :password_hash, :created_at, :updated_at)'
            );
            $statement->execute([
                'username' => $username,
                'password_hash' => $passwordHash,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $userId = (int) $this->pdo->lastInsertId();

            $settings = $this->pdo->prepare(
                'INSERT INTO user_settings (user_id, articles_per_page, created_at, updated_at) '
                . 'VALUES (:user_id, 25, :created_at, :updated_at)'
            );
            $settings->execute([
                'user_id' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->pdo->commit();

            return new User($userId, $username, $passwordHash, true);
        } catch (Throwable $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        }
    }

    public function findByUsername(string $username): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash, is_active FROM users WHERE username = :username COLLATE NOCASE'
        );
        $statement->execute(['username' => $username]);

        return $this->hydrate($statement->fetch());
    }

    public function findActiveById(int $id): ?User
    {
        $statement = $this->pdo->prepare(
            'SELECT id, username, password_hash, is_active FROM users '
            . 'WHERE id = :id AND is_active = 1'
        );
        $statement->execute(['id' => $id]);

        return $this->hydrate($statement->fetch());
    }

    /** @return list<array{id: int, username: string, is_active: bool, created_at: string}> */
    public function listAll(): array
    {
        $statement = $this->pdo->query(
            'SELECT id, username, is_active, created_at FROM users ORDER BY username COLLATE NOCASE'
        );
        if ($statement === false) {
            return [];
        }

        $users = [];
        while (($row = $statement->fetch()) !== false) {
            if (!is_array($row)) {
                continue;
            }
            $users[] = [
                'id' => (int) $row['id'],
                'username' => (string) $row['username'],
                'is_active' => (bool) $row['is_active'],
                'created_at' => (string) $row['created_at'],
            ];
        }

        return $users;
    }

    public function updatePassword(int $userId, string $passwordHash, string $now): void
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET password_hash = :password_hash, updated_at = :updated_at WHERE id = :id'
        );
        $statement->execute([
            'password_hash' => $passwordHash,
            'updated_at' => $now,
            'id' => $userId,
        ]);
    }

    public function setActiveByUsername(string $username, bool $active, string $now): bool
    {
        $statement = $this->pdo->prepare(
            'UPDATE users SET is_active = :is_active, updated_at = :updated_at '
            . 'WHERE username = :username COLLATE NOCASE'
        );
        $statement->execute([
            'is_active' => $active ? 1 : 0,
            'updated_at' => $now,
            'username' => $username,
        ]);

        return $statement->rowCount() === 1;
    }

    public function count(): int
    {
        $count = $this->pdo->query('SELECT COUNT(*) FROM users');

        return $count === false ? 0 : (int) $count->fetchColumn();
    }

    private function hydrate(mixed $row): ?User
    {
        if (!is_array($row)) {
            return null;
        }

        return new User(
            (int) $row['id'],
            (string) $row['username'],
            (string) $row['password_hash'],
            (bool) $row['is_active'],
        );
    }
}
