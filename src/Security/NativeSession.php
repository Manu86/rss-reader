<?php

declare(strict_types=1);

namespace App\Security;

use RuntimeException;

final class NativeSession implements Session
{
    private bool $closed = false;

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
        if ($this->closed) {
            // A writer after close() (the CSRF bootstrap arriving late, for
            // instance) reopens the same storage rather than silently losing
            // security state: the lock is only held again for this request.
            $this->reopen();
        }
        $_SESSION[$key] = $value;
    }

    public function regenerate(): void
    {
        if ($this->closed) {
            // A security write after close() (invalidating a deactivated
            // account, rotating the CSRF token) reopens the same storage
            // instead of silently losing security state.
            $this->reopen();
        }
        if (!session_regenerate_id(true)) {
            throw new RuntimeException('Impossible de renouveler la session.');
        }
    }

    public function invalidate(): void
    {
        $_SESSION = [];
        $this->regenerate();
    }

    public function close(): void
    {
        if ($this->closed || session_status() !== PHP_SESSION_ACTIVE) {
            return;
        }
        session_write_close();
        $this->closed = true;
    }

    private function reopen(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            $this->closed = false;

            return;
        }
        if (!session_start()) {
            throw new RuntimeException('Impossible de rouvrir la session.');
        }
        $this->closed = false;
    }
}
