<?php

declare(strict_types=1);

namespace App\Service;

use App\Config\AppConfig;
use App\Database\Migrator;
use RuntimeException;

final readonly class InstallationService
{
    public function __construct(
        private AppConfig $config,
        private Migrator $migrator,
    ) {}

    /** @return list<string> */
    public function prepare(): array
    {
        $runtimeRoot = dirname($this->config->secretFile);
        $directories = [
            dirname($this->config->databasePath),
            $this->config->mediaPath,
            $runtimeRoot . '/cache',
            $runtimeRoot . '/log',
            $runtimeRoot . '/tmp',
            dirname($this->config->cronLockPath),
        ];
        foreach ($directories as $path) {
            if (!is_dir($path) && !mkdir($path, 0700, true) && !is_dir($path)) {
                throw new RuntimeException(sprintf('Impossible de créer le répertoire %s.', $path));
            }
        }

        $secretDirectory = dirname($this->config->secretFile);
        if (!is_dir($secretDirectory) && !mkdir($secretDirectory, 0700, true) && !is_dir($secretDirectory)) {
            throw new RuntimeException('Impossible de créer le répertoire du secret applicatif.');
        }
        if (!is_file($this->config->secretFile)) {
            $handle = @fopen($this->config->secretFile, 'x');
            if ($handle === false) {
                throw new RuntimeException('Impossible de créer le secret applicatif.');
            }
            try {
                if (fwrite($handle, bin2hex(random_bytes(32)) . PHP_EOL) === false) {
                    throw new RuntimeException('Impossible d’écrire le secret applicatif.');
                }
            } finally {
                fclose($handle);
            }
            @chmod($this->config->secretFile, 0600);
        }

        return $this->migrator->migrate();
    }
}
