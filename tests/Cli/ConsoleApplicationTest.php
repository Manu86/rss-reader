<?php

declare(strict_types=1);

namespace Tests\Cli;

use App\Console\FileProcessLock;
use App\Database\ConnectionFactory;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ConsoleApplicationTest extends TestCase
{
    private string $projectRoot;
    private string $runtimeRoot;

    protected function setUp(): void
    {
        $this->projectRoot = dirname(__DIR__, 2);
        $this->runtimeRoot = sys_get_temp_dir() . '/rss-reader-cli-' . bin2hex(random_bytes(8));
        if (!mkdir($this->runtimeRoot, 0700, true) && !is_dir($this->runtimeRoot)) {
            throw new RuntimeException('Unable to create the CLI test directory.');
        }
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->runtimeRoot);
    }

    public function testInstallationAndUserAdministrationCommandsRunEndToEnd(): void
    {
        $installation = $this->runConsole(
            ['app:install', 'administrateur', '--password-stdin'],
            "correct horse battery staple\n",
        );
        self::assertSame(0, $installation['exit_code'], $installation['stderr']);
        self::assertStringContainsString('Installation terminée.', $installation['stdout']);
        self::assertFileExists($this->databasePath());
        self::assertFileExists($this->secretPath());
        self::assertSame(0600, fileperms($this->secretPath()) & 0777);

        $duplicateInstallation = $this->runConsole(['app:install', 'autre', '--password-stdin']);
        self::assertSame(1, $duplicateInstallation['exit_code']);
        self::assertStringContainsString('déjà un compte utilisateur', $duplicateInstallation['stderr']);

        $creation = $this->runConsole(
            ['user:create', 'alice', '--password-stdin'],
            "another correct password\n",
        );
        self::assertSame(0, $creation['exit_code'], $creation['stderr']);

        $listing = $this->runConsole(['user:list']);
        self::assertSame(0, $listing['exit_code'], $listing['stderr']);
        self::assertStringContainsString("administrateur\tactif", $listing['stdout']);
        self::assertStringContainsString("alice\tactif", $listing['stdout']);

        self::assertSame(0, $this->runConsole(['user:disable', 'alice'])['exit_code']);
        self::assertStringContainsString("alice\tdésactivé", $this->runConsole(['user:list'])['stdout']);
        self::assertSame(0, $this->runConsole(['user:enable', 'alice'])['exit_code']);

        $pdo = ConnectionFactory::create($this->databasePath());
        $pdo->exec("INSERT INTO user_remember_tokens "
            . "(user_id, selector, token_hash, expires_at, created_at) "
            . "SELECT id, 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'hash', '2030-01-01T00:00:00Z', "
            . "'2026-01-01T00:00:00Z' FROM users WHERE username = 'alice'");
        $pdo->exec("INSERT INTO user_remember_tokens "
            . "(user_id, selector, token_hash, expires_at, created_at) "
            . "SELECT id, 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'hash', '2030-01-01T00:00:00Z', "
            . "'2026-01-01T00:00:00Z' FROM users WHERE username = 'administrateur'");

        $passwordChange = $this->runConsole(
            ['user:password', 'alice', '--password-stdin'],
            "replacement secure password\n",
        );
        self::assertSame(0, $passwordChange['exit_code'], $passwordChange['stderr']);
        $statement = $pdo->query("SELECT password_hash FROM users WHERE username = 'alice'");
        self::assertNotFalse($statement);
        self::assertTrue(password_verify('replacement secure password', (string) $statement->fetchColumn()));
        // Réinitialiser le mot de passe re-sécurise le compte : les appareils
        // qui s'en souvenaient pour cet utilisateur ne sont plus valides.
        $tokens = $pdo->query('SELECT selector FROM user_remember_tokens');
        self::assertNotFalse($tokens);
        self::assertSame(['bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb'], $tokens->fetchAll(PDO::FETCH_COLUMN));

        $migration = $this->runConsole(['db:migrate']);
        self::assertSame(0, $migration['exit_code'], $migration['stderr']);
        self::assertStringContainsString('0 migration(s) appliquée(s).', $migration['stdout']);

        $unknown = $this->runConsole(['does:not-exist']);
        self::assertSame(2, $unknown['exit_code']);
        self::assertStringContainsString('Commande inconnue', $unknown['stderr']);
    }

    public function testMaintenanceCommandsApplyRetentionAndRespectTheSharedLock(): void
    {
        $installation = $this->runConsole(
            ['app:install', 'administrateur', '--password-stdin'],
            "correct horse battery staple\n",
        );
        self::assertSame(0, $installation['exit_code'], $installation['stderr']);

        $pdo = ConnectionFactory::create($this->databasePath());
        $userId = $this->integerQuery($pdo, 'SELECT id FROM users LIMIT 1');
        $this->insertDisabledFeedAndOldArticles($pdo, $userId);

        $cleanup = $this->runConsole(['articles:cleanup']);
        self::assertSame(0, $cleanup['exit_code'], $cleanup['stderr']);
        self::assertStringContainsString('1 ancien(s) article(s) supprimé(s)', $cleanup['stdout']);
        self::assertSame(1, $this->integerQuery($pdo, 'SELECT COUNT(*) FROM articles'));
        self::assertSame(1, $this->integerQuery($pdo, 'SELECT COUNT(*) FROM articles WHERE is_favorite = 1'));

        $refresh = $this->runConsole(['feeds:refresh']);
        self::assertSame(0, $refresh['exit_code'], $refresh['stderr']);
        self::assertStringContainsString('Synchronisation terminée : 0 flux', $refresh['stdout']);

        $rebuild = $this->runConsole(['fts:rebuild']);
        self::assertSame(0, $rebuild['exit_code'], $rebuild['stderr']);
        self::assertStringContainsString('Index de recherche reconstruit.', $rebuild['stdout']);

        $lock = new FileProcessLock($this->lockPath());
        self::assertTrue($lock->acquire());
        try {
            $lockedCleanup = $this->runConsole(['articles:cleanup']);
            self::assertSame(75, $lockedCleanup['exit_code']);
            self::assertStringContainsString('déjà en cours', $lockedCleanup['stderr']);
        } finally {
            $lock->release();
        }
    }

    /**
     * @param list<string> $arguments
     * @return array{exit_code: int, stdout: string, stderr: string}
     */
    private function runConsole(array $arguments, string $standardInput = ''): array
    {
        $command = array_merge([PHP_BINARY, $this->projectRoot . '/bin/console'], $arguments);
        $pipes = [];
        $process = proc_open(
            $command,
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            $this->projectRoot,
            $this->environment(),
        );
        if (!is_resource($process)) {
            throw new RuntimeException('Unable to start the console process.');
        }
        foreach ([0, 1, 2] as $descriptor) {
            if (!isset($pipes[$descriptor]) || !is_resource($pipes[$descriptor])) {
                proc_terminate($process);
                throw new RuntimeException('Unable to open a console process pipe.');
            }
        }

        fwrite($pipes[0], $standardInput);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [
            'exit_code' => $exitCode,
            'stdout' => $stdout === false ? '' : $stdout,
            'stderr' => $stderr === false ? '' : $stderr,
        ];
    }

    /** @return array<string, string> */
    private function environment(): array
    {
        return [
            'APP_ENV' => 'test',
            'APP_DATABASE_PATH' => $this->databasePath(),
            'APP_SECRET_FILE' => $this->secretPath(),
            'APP_SESSION_SECURE' => '0',
            'APP_CRON_LOCK_PATH' => $this->lockPath(),
            'APP_MEDIA_PATH' => $this->runtimeRoot . '/media',
        ];
    }

    private function databasePath(): string
    {
        return $this->runtimeRoot . '/database/app.sqlite';
    }

    private function secretPath(): string
    {
        return $this->runtimeRoot . '/app.secret';
    }

    private function lockPath(): string
    {
        return $this->runtimeRoot . '/tmp/maintenance.lock';
    }

    private function insertDisabledFeedAndOldArticles(PDO $pdo, int $userId): void
    {
        $feed = $pdo->prepare(
            'INSERT INTO feeds '
            . '(user_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:user_id, :name, :url, 0, :status, :created_at, :updated_at)'
        );
        $feed->execute([
            'user_id' => $userId,
            'name' => 'Flux désactivé',
            'url' => 'https://example.test/feed.xml',
            'status' => 'never',
            'created_at' => '2020-01-01T00:00:00Z',
            'updated_at' => '2020-01-01T00:00:00Z',
        ]);
        $feedId = (int) $pdo->lastInsertId();

        $article = $pdo->prepare(
            'INSERT INTO articles '
            . '(user_id, feed_id, title, discovered_at, is_favorite, deduplication_hash, created_at, updated_at) '
            . 'VALUES (:user_id, :feed_id, :title, :discovered_at, :favorite, :hash, :created_at, :updated_at)'
        );
        foreach ([['Ancien', 0], ['Favori ancien', 1]] as [$title, $favorite]) {
            $article->execute([
                'user_id' => $userId,
                'feed_id' => $feedId,
                'title' => $title,
                'discovered_at' => '2020-01-01T00:00:00Z',
                'favorite' => $favorite,
                'hash' => hash('sha256', $title),
                'created_at' => '2020-01-01T00:00:00Z',
                'updated_at' => '2020-01-01T00:00:00Z',
            ]);
        }
    }

    private function integerQuery(PDO $pdo, string $sql): int
    {
        $statement = $pdo->query($sql);
        if ($statement === false) {
            throw new RuntimeException('Unable to execute a CLI test query.');
        }

        return (int) $statement->fetchColumn();
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if ($entries === false) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $entryPath = $path . '/' . $entry;
            if (is_dir($entryPath) && !is_link($entryPath)) {
                $this->removeTree($entryPath);
            } else {
                unlink($entryPath);
            }
        }
        rmdir($path);
    }
}
