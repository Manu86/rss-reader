<?php

declare(strict_types=1);

namespace App\Model;

final readonly class RecommendationEmailRecipient
{
    public function __construct(
        public int $userId,
        public string $username,
        public string $email,
        public string $frequency,
        public ?string $lastSentAt,
    ) {}
}
