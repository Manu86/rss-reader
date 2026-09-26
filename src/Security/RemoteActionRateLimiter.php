<?php

declare(strict_types=1);

namespace App\Security;

use App\Clock\Clock;
use App\Exception\RateLimitException;
use App\Repository\RemoteActionAttemptRepository;

final readonly class RemoteActionRateLimiter
{
    private const MAX_ATTEMPTS = 10;
    private const WINDOW = '5 minutes';

    public function __construct(
        private RemoteActionAttemptRepository $attempts,
        private Clock $clock,
    ) {}

    public function consume(int $userId, string $action): void
    {
        $now = $this->clock->now();
        $since = $now->modify('-' . self::WINDOW)->format('Y-m-d\TH:i:s\Z');
        if ($this->attempts->countSince($userId, $action, $since) >= self::MAX_ATTEMPTS) {
            throw new RateLimitException();
        }

        $this->attempts->add($userId, $action, $now->format('Y-m-d\TH:i:s\Z'));
        $this->attempts->pruneBefore($now->modify('-1 day')->format('Y-m-d\TH:i:s\Z'));
    }
}
