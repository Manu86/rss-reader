<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;
use Throwable;

final readonly class Migrator
{
    public function __construct(
        private PDO $pdo,
        private string $migrationDirectory,
    ) {}

    /** @return list<string> */
    public function migrate(): array
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations ('
            . 'version TEXT PRIMARY KEY, applied_at TEXT NOT NULL)'
        );

        $files = glob($this->migrationDirectory . '/*.sql');
        if ($files === false) {
            throw new RuntimeException('Impossible de lire le répertoire des migrations.');
        }
        sort($files, SORT_STRING);

        $applied = [];
        foreach ($files as $file) {
            $version = basename($file, '.sql');
            if ($this->isApplied($version)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new RuntimeException(sprintf('Migration illisible : %s.', $version));
            }

            $this->pdo->beginTransaction();
            try {
                $this->pdo->exec($sql);
                $statement = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, :applied_at)'
                );
                $statement->execute([
                    'version' => $version,
                    'applied_at' => gmdate('Y-m-d\TH:i:s\Z'),
                ]);
                $this->pdo->commit();
                $applied[] = $version;
            } catch (Throwable $exception) {
                if ($this->pdo->inTransaction()) {
                    $this->pdo->rollBack();
                }
                throw $exception;
            }
        }

        return $applied;
    }

    private function isApplied(string $version): bool
    {
        $statement = $this->pdo->prepare(
            'SELECT 1 FROM schema_migrations WHERE version = :version'
        );
        $statement->execute(['version' => $version]);

        return $statement->fetchColumn() !== false;
    }
}
