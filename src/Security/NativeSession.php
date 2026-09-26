<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class NativeSession implements Session
{
    public function __construct(
        string $name,
        int $lifetime,
        bool $secure,
    ) {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }

        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        ini_set('session.gc_maxlifetime', (string) $lifetime);
        session_name($name);
        session_set_cookie_params([
            'lifetime' => $lifetime,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        if (!session_start()) {
            throw new RuntimeException('Impossible de démarrer la session.');
        }
    }

    public function get(string $key): mixed
    {
        return $_SESSION[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Impossible de renouveler la session.');
        }
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        $this->regenerate();
    }
}
