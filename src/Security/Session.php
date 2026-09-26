<?php

declare(strict_types=1);

namespace App\Security;

interface Session
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function regenerate(): void;

    public function invalidate(): void;
}
