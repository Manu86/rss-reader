<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\UnauthorizedException;
use App\Model\User;
use App\Service\AuthenticationService;

final readonly class CurrentUser
{
    private const SESSION_KEY = 'authenticated_user_id';

    public function __construct(
        private Session $session,
        private AuthenticationService $authentication,
    ) {}

    public function set(User $user): void
    {
        $this->session->set(self::SESSION_KEY, $user->id);
    }

    public function isAuthenticated(): bool
    {
        return is_int($this->session->get(self::SESSION_KEY));
    }

    /**
     * Restores a session from an already validated user, regenerating the ID so
     * a pre-login identifier can never be reused.
     */
    public function restore(int $userId): void
    {
        $this->session->regenerate();
        $this->session->set(self::SESSION_KEY, $userId);
    }

    public function require(): User
    {
        $userId = $this->session->get(self::SESSION_KEY);
        if (!is_int($userId)) {
            throw new UnauthorizedException();
        }
        $user = $this->authentication->activeUser($userId);
        if ($user === null) {
            $this->session->invalidate();
            throw new UnauthorizedException();
        }

        return $user;
    }
}
