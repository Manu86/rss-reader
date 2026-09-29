<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\AuthenticationException;
use App\Model\User;
use App\Repository\UserRepository;
use App\Security\LoginRateLimiter;

final readonly class AuthenticationService
{
    private const DUMMY_PASSWORD_HASH = '$2y$10$ryojQk718QYmckycTZ5nIOvdMiaytitHkemFsTxYGHljag98dvvM6';

    public function __construct(
        private UserRepository $users,
        private LoginRateLimiter $rateLimiter,
    ) {}

    public function login(string $username, string $password, string $address): User
    {
        $username = trim($username);
        $this->rateLimiter->assertAllowed($username, $address);
        $user = $this->users->findByUsername($username);
        $hash = $user === null ? self::DUMMY_PASSWORD_HASH : $user->passwordHash;
        $validPassword = password_verify($password, $hash);

        if ($user === null || !$validPassword || !$user->active) {
            $this->rateLimiter->recordFailure($username, $address);
            throw new AuthenticationException();
        }

        if (password_needs_rehash($user->passwordHash, PASSWORD_DEFAULT)) {
            $this->users->updatePassword($user->id, password_hash($password, PASSWORD_DEFAULT), gmdate('Y-m-d\TH:i:s\Z'));
        }

        $this->rateLimiter->clear($username);

        return $user;
    }

    public function activeUser(int $userId): ?User
    {
        return $this->users->findActiveById($userId);
    }
}
