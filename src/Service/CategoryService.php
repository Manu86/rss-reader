<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Category;
use App\Repository\CategoryRepository;
use PDOException;

final readonly class CategoryService
{
    public function __construct(
        private CategoryRepository $categories,
        private Clock $clock,
    ) {}

    /** @return list<Category> */
    public function list(int $userId): array
    {
        return $this->categories->listOwned($userId);
    }

    public function create(int $userId, string $name): Category
    {
        try {
            return $this->categories->create($userId, $this->validateName($name), $this->now());
        } catch (PDOException $exception) {
            $this->throwDuplicate($exception);
        }
    }

    public function update(int $categoryId, int $userId, string $name): Category
    {
        $category = $this->categories->findOwned($categoryId, $userId);
        if ($category === null) {
            throw new NotFoundException('Catégorie introuvable.');
        }

        try {
            return $this->categories->update($category, $this->validateName($name), $this->now());
        } catch (PDOException $exception) {
            $this->throwDuplicate($exception);
        }
    }

    public function delete(int $categoryId, int $userId): void
    {
        if (!$this->categories->deleteOwned($categoryId, $userId)) {
            throw new NotFoundException('Catégorie introuvable.');
        }
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 1 || $length > 200) {
            throw new ValidationException([
                'name' => 'Le nom doit contenir entre 1 et 200 caractères.',
            ]);
        }

        return $name;
    }

    private function throwDuplicate(PDOException $exception): never
    {
        if ((string) $exception->getCode() === '23000') {
            throw new ConflictException(
                'CATEGORY_ALREADY_EXISTS',
                'Une catégorie portant ce nom existe déjà.',
            );
        }

        throw $exception;
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
