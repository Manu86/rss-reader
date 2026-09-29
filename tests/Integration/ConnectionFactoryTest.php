<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;

final class ConnectionFactoryTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rss-reader-connection-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testFileDatabaseUsesWalAndKeepsDurableCommits(): void
    {
        $path = $this->directory . '/rss-reader.sqlite';
        $pdo = ConnectionFactory::create($path);
        self::assertSame('wal', strtolower((string) $this->pragma($pdo, 'PRAGMA journal_mode')));
        // Un checkpoint automatique borne la taille du journal : sans cela, le
        // fichier -wal grossit a chaque import et un seul processus peut le
        // maintenir indefiniment, meme si la base ne change plus.
        self::assertSame(512, (int) $this->pragma($pdo, 'PRAGMA wal_autocheckpoint'));
        // Le mode WAL ne doit pas alléger la durabilite des commits : read states,
        // favoris et reglages ne sont pas reconstructibles depuis les flux.
        self::assertSame(2, (int) $this->pragma($pdo, 'PRAGMA synchronous'));
        self::assertSame(5000, (int) $this->pragma($pdo, 'PRAGMA busy_timeout'));
        self::assertSame(1, (int) $this->pragma($pdo, 'PRAGMA foreign_keys'));

        $pdo->exec('CREATE TABLE journal_probe (id INTEGER PRIMARY KEY, value TEXT NOT NULL)');
        $pdo->beginTransaction();
        $pdo->exec("INSERT INTO journal_probe (value) VALUES ('kept')");
        $pdo->commit();
        $this->checkpointTruncatesTheWriteAheadLog($pdo);
        self::assertSame('kept', (string) $this->pragma($pdo, 'SELECT value FROM journal_probe'));

        // Le mode est un propriete du fichier : la verification au demarrage ne
        // peut pas reposer sur la seule session qui vient de la definir.
        self::assertSame('wal', strtolower((string) $this->pragma(
            ConnectionFactory::create($path),
            'PRAGMA journal_mode',
        )));
    }

    public function testInMemoryDatabaseIsNotRequiredToUseWal(): void
    {
        // Les tests utilisent des bases en memoire, sans fichier ni journal : la
        // verification de WAL ne doit pas s'appliquer a cette connexion.
        $pdo = ConnectionFactory::createMemory();
        self::assertSame('memory', strtolower((string) $this->pragma($pdo, 'PRAGMA journal_mode')));
    }

    private function pragma(PDO $pdo, string $statement): mixed
    {
        $result = $pdo->query($statement);
        self::assertNotFalse($result);

        return $result->fetchColumn();
    }

    private function checkpointTruncatesTheWriteAheadLog(PDO $pdo): void
    {
        self::assertFileExists($this->directory . '/rss-reader.sqlite-wal');
        $pdo->exec('PRAGMA wal_checkpoint(TRUNCATE)');
        self::assertSame(0, (int) $this->pragma($pdo, 'PRAGMA wal_checkpoint(TRUNCATE)'));
    }
}
