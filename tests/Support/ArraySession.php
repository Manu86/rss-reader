<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Security\Session;

final class ArraySession implements Session
{
    /** @var array<string, mixed> */
    private array $values = [];

    public int $regenerationCount = 0;

    public bool $closed = false;

    public function get(string $key): mixed
    {
        return $this->values[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        if ($this->closed) {
            // Same re-open semantics as NativeSession: a late writer (the
            // CSRF bootstrap) keeps working instead of silently failing.
            $this->closed = false;
        }
        $this->values[$key] = $value;
    }

    public function regenerate(): void
    {
        if ($this->closed) {
            $this->closed = false;
        }
        ++$this->regenerationCount;
    }

    public function invalidate(): void
    {
        $this->values = [];
        $this->regenerate();
    }

    public function close(): void
    {
        $this->closed = true;
    }
}
