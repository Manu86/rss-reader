<?php

declare(strict_types=1);

namespace App\Console;

use RuntimeException;

final class PasswordReader
{
    public function read(bool $fromStandardInput, bool $confirm = true): string
    {
        if ($fromStandardInput) {
            $line = fgets(STDIN);
            if ($line === false) {
                throw new RuntimeException('Aucun mot de passe reçu sur l’entrée standard.');
            }

            return rtrim($line, "\r\n");
        }

        if (!function_exists('posix_isatty') || !posix_isatty(STDIN)) {
            throw new RuntimeException('Utilisez --password-stdin hors d’un terminal interactif.');
        }

        $password = $this->readHidden('Mot de passe : ');
        if ($confirm) {
            $confirmation = $this->readHidden('Confirmation : ');
            if (!hash_equals($password, $confirmation)) {
                throw new RuntimeException('Les mots de passe ne correspondent pas.');
            }
        }

        return $password;
    }

    private function readHidden(string $prompt): string
    {
        fwrite(STDERR, $prompt);
        system('stty -echo');
        try {
            $line = fgets(STDIN);
        } finally {
            system('stty echo');
            fwrite(STDERR, PHP_EOL);
        }
        if ($line === false) {
            throw new RuntimeException('Impossible de lire le mot de passe.');
        }

        return rtrim($line, "\r\n");
    }
}
