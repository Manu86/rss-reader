<?php

declare(strict_types=1);

namespace App\Repository;

use App\Model\Category;
use PDO;

final readonly class CategoryRepository
{
    public function __construct(private PDO $pdo) {}

    /** @return list<Category> */
    public function listOwned(int $userId): array
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, created_at, updated_at FROM categories '
            . 'WHERE user_id = :user_id ORDER BY name COLLATE NOCASE, id'
        );
        $statement->execute(['user_id' => $userId]);
        $categories = [];
        while (($row = $statement->fetch()) !== false) {
            $category = $this->hydrate($row);
            if ($category !== null) {
                $categories[] = $category;
            }
        }

        return $categories;
    }

    public function findOwned(int $categoryId, int $userId): ?Category
    {
        $statement = $this->pdo->prepare(
            'SELECT id, user_id, name, created_at, updated_at FROM categories '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $categoryId, 'user_id' => $userId]);

        return $this->hydrate($statement->fetch());
    }

    public function existsOwned(int $categoryId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM categories WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $categoryId, 'user_id' => $userId]);

        return $statement->fetchColumn() !== false;
    }

    public function create(int $userId, string $name, string $now): Category
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO categories (user_id, name, created_at, updated_at) '
            . 'VALUES (:user_id, :name, :created_at, :updated_at)'
        );
        $statement->execute([
            'user_id' => $userId,
            'name' => $name,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $category = $this->findOwned((int) $this->pdo->lastInsertId(), $userId);
        if ($category === null) {
            throw new \RuntimeException('La catégorie créée est introuvable.');
        }

        return $category;
    }

    public function update(Category $category, string $name, string $now): Category
    {
        $statement = $this->pdo->prepare(
            'UPDATE categories SET name = :name, updated_at = :updated_at '
            . 'WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute([
            'name' => $name,
            'updated_at' => $now,
            'id' => $category->id,
            'user_id' => $category->userId,
        ]);

        $updated = $this->findOwned($category->id, $category->userId);
        if ($updated === null) {
            throw new \RuntimeException('La catégorie modifiée est introuvable.');
        }

        return $updated;
    }

    public function deleteOwned(int $categoryId, int $userId): bool
    {
        $statement = $this->pdo->prepare(
            'DELETE FROM categories WHERE id = :id AND user_id = :user_id'
        );
        $statement->execute(['id' => $categoryId, 'user_id' => $userId]);

        return $statement->rowCount() === 1;
    }

    private function hydrate(mixed $row): ?Category
    {
        if (!is_array($row)) {
            return null;
        }

        return new Category(
            (int) $row['id'],
            (int) $row['user_id'],
            (string) $row['name'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
        );
    }
}
