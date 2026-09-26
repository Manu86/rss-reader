<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Security\Session;

final class ArraySession implements Session
{
    /** @var array<string, mixed> */
    private array $values = [];

    public int $regenerationCount = 0;

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->values[$key] = $value;
    }

    public function regenerate(): void
    {
        ++$this->regenerationCount;
    }

    public function invalidate(): void
    {
        $this->values = [];
        $this->regenerate();
    }
}
