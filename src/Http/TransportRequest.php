<?php

declare(strict_types=1);

namespace App\Http;

final readonly class TransportRequest
{
    /** @param array<string, string> $headers */
    public function __construct(
        public string $url,
        public string $host,
        public int $port,
        public string $address,
        public array $headers,
        public int $connectTimeoutMs,
        public int $timeoutMs,
        public int $maxResponseBytes,
        public string $userAgent,
    ) {}
}
