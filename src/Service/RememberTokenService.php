<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Http\SetCookie;
use App\Repository\RememberTokenRepository;
use PDOException;

final readonly class RememberTokenService
{
    private const TIMESTAMP = 'Y-m-d\TH:i:s\Z';
    private const SELECTOR_BYTES = 16;
    private const VALIDATOR_BYTES = 32;
    private const SELECTOR_LENGTH = 32;
    private const COOKIE_PATTERN = '/\A[a-f0-9]{32}\.[a-f0-9]{64}\z/';

    public function __construct(
        private RememberTokenRepository $tokens,
        private AuthenticationService $authentication,
        private Clock $clock,
        private int $lifetime,
        private string $cookieName,
        private bool $secure,
    ) {
        if ($lifetime <= 0) {
            throw new \InvalidArgumentException('La durée du cookie doit être positive.');
        }
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $cookieName) !== 1) {
            throw new \InvalidArgumentException('Le nom du cookie est invalide.');
        }
    }

    public function cookieName(): string
    {
        return $this->cookieName;
    }

    /**
     * Issues a fresh token and returns the cookie that carries it. Expired rows
     * are pruned here so the table stays bounded without a scheduled job.
     */
    public function issue(int $userId): SetCookie
    {
        $selector = bin2hex(random_bytes(self::SELECTOR_BYTES));
        $validator = bin2hex(random_bytes(self::VALIDATOR_BYTES));

        // Persistent login is a convenience: if it cannot be stored, the
        // session must still be created rather than failing a login whose
        // password has already been verified.
        try {
            $now = $this->clock->now();
            $this->tokens->deleteExpired($now->format(self::TIMESTAMP));
            $this->tokens->create(
                $userId,
                $selector,
                self::hashValidator($validator),
                $now->modify(sprintf('+%d seconds', $this->lifetime))->format(self::TIMESTAMP),
                $now->format(self::TIMESTAMP),
            );
        } catch (PDOException) {
            self::reportStorageFailure();
        }

        return new SetCookie(
            $this->cookieName,
            $selector . '.' . $validator,
            $this->lifetime,
            $this->secure,
        );
    }

    /**
     * Returns the active user behind a valid cookie, or null. The validator is
     * compared against its stored hash, so a database leak never yields a
     * usable cookie, and the account must still be active.
     */
    public function resolve(?string $cookieValue): ?int
    {
        $parsed = $this->parse($cookieValue);
        if ($parsed === null) {
            return null;
        }

        try {
            return $this->resolveToken($parsed);
        } catch (PDOException) {
            self::reportStorageFailure();

            return null;
        }
    }

    /**
     * @param array{selector: string, validator: string} $parsed
     */
    private function resolveToken(array $parsed): ?int
    {
        $token = $this->tokens->findBySelector($parsed['selector']);
        if ($token === null) {
            return null;
        }

        $now = $this->clock->now()->format(self::TIMESTAMP);
        if ($token->expiresAt <= $now) {
            $this->tokens->deleteBySelector($parsed['selector']);

            return null;
        }

        if (!hash_equals($token->tokenHash, self::hashValidator($parsed['validator']))) {
            return null;
        }

        $user = $this->authentication->activeUser($token->userId);
        if ($user === null) {
            $this->tokens->deleteAllForUser($token->userId);

            return null;
        }

        return $user->id;
    }

    /** Deletes the row behind a cookie without touching the session. */
    public function revoke(?string $cookieValue): void
    {
        $parsed = $this->parse($cookieValue);
        if ($parsed === null) {
            return;
        }

        try {
            $this->tokens->deleteBySelector($parsed['selector']);
        } catch (PDOException) {
            self::reportStorageFailure();
        }
    }

    public function revokeAll(int $userId): void
    {
        try {
            $this->tokens->deleteAllForUser($userId);
        } catch (PDOException) {
            self::reportStorageFailure();
        }
    }

    public function clearCookie(): SetCookie
    {
        return SetCookie::cleared($this->cookieName, $this->secure);
    }

    /** @return array{selector: string, validator: string}|null */
    private function parse(?string $cookieValue): ?array
    {
        if ($cookieValue === null || preg_match(self::COOKIE_PATTERN, $cookieValue) !== 1) {
            return null;
        }

        return [
            'selector' => substr($cookieValue, 0, self::SELECTOR_LENGTH),
            'validator' => substr($cookieValue, self::SELECTOR_LENGTH + 1),
        ];
    }

    private static function hashValidator(string $validator): string
    {
        return hash('sha256', $validator);
    }

    /**
     * The token table backs an optional convenience, so a storage failure must
     * never take down an unrelated endpoint. Logging out or changing a password
     * still succeeds, the session simply stops being persistent. Details are
     * deliberately not logged, so no SQL or filesystem path can leak.
     */
    private static function reportStorageFailure(): void
    {
        error_log('Remember me storage is unavailable; persistent login is disabled.');
    }
}
