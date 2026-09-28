<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Exception\AuthenticationException;
use App\Exception\ValidationException;
use App\Model\User;
use App\Model\UserSettings;
use App\Repository\UserRepository;
use App\Security\PasswordPolicy;
use PDOException;

final readonly class UserService
{
    public function __construct(
        private UserRepository $users,
        private PasswordPolicy $passwordPolicy,
        private Clock $clock,
    ) {}

    public function create(string $username, string $password): User
    {
        $username = trim($username);
        if (preg_match('/\A[\p{L}\p{N}._-]{3,64}\z/u', $username) !== 1) {
            throw new ValidationException([
                'username' => 'Le nom doit contenir 3 à 64 lettres, chiffres ou caractères . _ -.',
            ]);
        }
        $this->passwordPolicy->validate($password);

        try {
            return $this->users->create(
                $username,
                password_hash($password, PASSWORD_DEFAULT),
                $this->now(),
            );
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000') {
                throw new ValidationException(['username' => 'Ce nom d’utilisateur existe déjà.']);
            }
            throw $exception;
        }
    }

    public function changeOwnPassword(User $user, string $currentPassword, string $newPassword): void
    {
        if (!password_verify($currentPassword, $user->passwordHash)) {
            throw new AuthenticationException('Le mot de passe actuel est invalide.');
        }
        $this->passwordPolicy->validate($newPassword, 'new_password');
        $this->users->updatePassword(
            $user->id,
            password_hash($newPassword, PASSWORD_DEFAULT),
            $this->now(),
        );
    }

    public function updateOwnProfile(User $user, string $email, string $frequency): User
    {
        $email = trim($email);
        $normalized = $email === '' ? null : $email;
        if ($normalized !== null
            && (strlen($normalized) > 254 || filter_var($normalized, FILTER_VALIDATE_EMAIL) === false)) {
            throw new ValidationException([
                'email' => 'Saisissez une adresse email valide de 254 caractères maximum.',
            ]);
        }
        if (!in_array($frequency, UserSettings::ALLOWED_RECOMMENDATION_EMAIL_FREQUENCIES, true)) {
            throw new ValidationException([
                'recommendation_email_frequency' => 'La fréquence doit être never, daily, weekly ou monthly.',
            ]);
        }
        if ($normalized === null && $frequency !== UserSettings::DEFAULT_RECOMMENDATION_EMAIL_FREQUENCY) {
            throw new ValidationException([
                'email' => 'Une adresse email est requise pour activer les recommandations par email.',
            ]);
        }

        $this->users->updateProfile($user->id, $normalized, $frequency, $this->now());
        $updated = $this->users->findActiveById($user->id);
        if ($updated === null) {
            throw new \RuntimeException('Le compte utilisateur est introuvable.');
        }

        return $updated;
    }

    public function resetPassword(string $username, string $password): bool
    {
        $user = $this->users->findByUsername(trim($username));
        if ($user === null) {
            return false;
        }
        $this->passwordPolicy->validate($password);
        $this->users->updatePassword(
            $user->id,
            password_hash($password, PASSWORD_DEFAULT),
            $this->now(),
        );

        return true;
    }

    public function setActive(string $username, bool $active): bool
    {
        return $this->users->setActiveByUsername(trim($username), $active, $this->now());
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
