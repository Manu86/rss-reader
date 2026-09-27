<?php

declare(strict_types=1);

namespace App\Model;

final readonly class RememberToken
{
    public function __construct(
        public int $id,
        public int $userId,
        public string $tokenHash,
        public string $expiresAt,
    ) {}
}
