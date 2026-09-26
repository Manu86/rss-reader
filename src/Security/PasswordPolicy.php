<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\ValidationException;

final class PasswordPolicy
{
    public function validate(string $password, string $field = 'password'): void
    {
        $length = mb_strlen($password, 'UTF-8');
        if ($length < 12) {
            throw new ValidationException([
                $field => 'Le mot de passe doit contenir au moins 12 caractères.',
            ]);
        }
        // Bcrypt ne prend en compte que 72 octets. Cette limite conservatrice
        // évite toute troncature silencieuse avec les cibles PHP supportées.
        if (strlen($password) > 72) {
            throw new ValidationException([
                $field => 'Le mot de passe ne doit pas dépasser 72 octets.',
            ]);
        }
    }
}
