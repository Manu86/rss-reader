<?php

declare(strict_types=1);

namespace App\Model;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $username,
        public string $passwordHash,
        public bool $active,
    ) {}

    /** @return array{id: int, username: string} */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'username' => $this->username,
        ];
    }
}
