<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\CsrfException;

final readonly class CsrfTokenManager
{
    private const SESSION_KEY = 'csrf_token';

    public function __construct(private Session $session) {}

    public function token(): string
    {
        $token = $this->session->get(self::SESSION_KEY);
        if (!is_string($token) || strlen($token) !== 64) {
            return $this->rotate();
        }

        return $token;
    }

    public function rotate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function validate(?string $submittedToken): void
    {
        $expected = $this->session->get(self::SESSION_KEY);
        if (!is_string($submittedToken) || !is_string($expected) || !hash_equals($expected, $submittedToken)) {
            throw new CsrfException();
        }
    }
}
