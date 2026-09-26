<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Category
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $name,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @return array{id: int, name: string, created_at: string, updated_at: string} */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
