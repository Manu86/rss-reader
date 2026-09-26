<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Http\DnsResolver;

final readonly class FakeDnsResolver implements DnsResolver
{
    /** @param array<string, list<string>> $answers */
    public function __construct(private array $answers) {}

    public function resolve(string $host): array
    {
        return $this->answers[$host] ?? [];
    }
}
