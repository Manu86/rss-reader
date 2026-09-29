<?php

declare(strict_types=1);

namespace App\Database;

use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public static function create(string $databasePath): PDO
    {
        $directory = dirname($databasePath);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire de la base de données.');
        }

        $pdo = new PDO('sqlite:' . $databasePath, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');
        $pdo->exec('PRAGMA journal_mode = WAL');
        $pdo->exec('PRAGMA wal_autocheckpoint = 512');
        $statement = $pdo->query('PRAGMA journal_mode');
        $journalMode = $statement !== false ? $statement->fetchColumn() : false;
        if (!is_string($journalMode) || strtolower($journalMode) !== 'wal') {
            throw new RuntimeException('Le mode journal WAL est requis pour la base de donnees.');
        }

        return $pdo;
    }

    public static function createMemory(): PDO
    {
        $pdo = new PDO('sqlite::memory:', null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
