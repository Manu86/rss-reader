<?php

declare(strict_types=1);

namespace App\Security;

interface Session
{
    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function regenerate(): void;

    public function invalidate(): void;

    /**
     * Releases the session write lock so concurrent requests stop queuing
     * behind each other. A later set() reopens the storage instead of being
     * silently lost, so no security state can be dropped unnoticed.
     */
    public function close(): void;
}
