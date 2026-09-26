<?php

declare(strict_types=1);

namespace App\Security;

use App\Clock\Clock;
use App\Exception\RateLimitException;
use App\Repository\LoginAttemptRepository;

final readonly class LoginRateLimiter
{
    private const MAX_ATTEMPTS = 5;
    private const WINDOW = '15 minutes';

    public function __construct(
        private LoginAttemptRepository $attempts,
        private Clock $clock,
        private string $secret,
    ) {}

    public function assertAllowed(string $username, string $address): void
    {
        [$identifierHash, $addressHash] = $this->keys($username, $address);
        $since = $this->clock->now()->modify('-' . self::WINDOW)->format('Y-m-d\TH:i:s\Z');
        if ($this->attempts->countSince($identifierHash, $addressHash, $since) >= self::MAX_ATTEMPTS) {
            throw new RateLimitException();
        }
    }

    public function recordFailure(string $username, string $address): void
    {
        [$identifierHash, $addressHash] = $this->keys($username, $address);
        $now = $this->clock->now();
        $this->attempts->add(
            $identifierHash,
            $addressHash,
            $now->format('Y-m-d\TH:i:s\Z'),
        );
        $this->attempts->pruneBefore($now->modify('-1 day')->format('Y-m-d\TH:i:s\Z'));
    }

    public function clear(string $username, string $address): void
    {
        [$identifierHash, $addressHash] = $this->keys($username, $address);
        $this->attempts->clear($identifierHash, $addressHash);
    }

    /** @return array{string, string} */
    private function keys(string $username, string $address): array
    {
        return [
            hash_hmac('sha256', mb_strtolower(trim($username), 'UTF-8'), $this->secret),
            hash_hmac('sha256', $address, $this->secret),
        ];
    }
}
