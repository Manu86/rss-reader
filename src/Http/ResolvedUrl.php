<?php

declare(strict_types=1);

namespace App\Http;

final readonly class ResolvedUrl
{
    /** @param non-empty-list<string> $addresses */
    public function __construct(
        public string $url,
        public string $scheme,
        public string $host,
        public int $port,
        public array $addresses,
    ) {}
}
