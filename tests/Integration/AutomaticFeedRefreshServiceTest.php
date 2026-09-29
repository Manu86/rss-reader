<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Exception\FeedBusyException;
use App\Exception\FeedSyncException;
use App\Model\Feed;
use App\Repository\FeedRepository;
use App\Service\AutomaticFeedRefreshService;
use App\Service\FeedRefresher;
use PDO;
use PHPUnit\Framework\TestCase;

final class AutomaticFeedRefreshServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
        $this->insertUser(1, 'alice', true);
        $this->insertUser(2, 'bob', false);
        $this->insertFeed(10, 1, 'Succès', true);
        $this->insertFeed(11, 1, 'Échec', true);
        $this->insertFeed(12, 1, 'Désactivé', false);
        $this->insertFeed(20, 2, 'Utilisateur désactivé', true);
    }

    public function testOnlyActiveFeedsOfEnabledUsersRunAndFailuresDoNotStopTheBatch(): void
    {
        $refresher = new class implements FeedRefresher {
            /** @var list<int> */
            public array $seen = [];

            public function refresh(Feed $feed): array
            {
                $this->seen[] = $feed->id;
                if ($feed->id === 11) {
                    throw FeedSyncException::remoteFailure();
                }

                return ['feed' => $feed, 'imported_articles' => 3, 'not_modified' => true];
            }
        };
        $service = new AutomaticFeedRefreshService(new FeedRepository($this->pdo), $refresher);

        $summary = $service->run();

        self::assertSame([10, 11], $refresher->seen);
        self::assertSame([
            'total' => 2,
            'successful' => 1,
            'failed' => 1,
            'skipped' => 0,
            'imported_articles' => 3,
            'not_modified' => 1,
        ], $summary);
    }

    public function testBusyFeedIsSkippedInsteadOfCountedAsAFailure(): void
    {
        $refresher = new class implements FeedRefresher {
            /** @var list<int> */
            public array $seen = [];

            public function refresh(Feed $feed): array
            {
                $this->seen[] = $feed->id;
                if ($feed->id === 10) {
                    throw FeedBusyException::alreadyRefreshing();
                }

                return ['feed' => $feed, 'imported_articles' => 1, 'not_modified' => false];
            }
        };
        $service = new AutomaticFeedRefreshService(new FeedRepository($this->pdo), $refresher);

        $summary = $service->run();

        self::assertSame([10, 11], $refresher->seen);
        self::assertSame([
            'total' => 2,
            'successful' => 1,
            'failed' => 0,
            'skipped' => 1,
            'imported_articles' => 1,
            'not_modified' => 0,
        ], $summary);
    }

    private function insertUser(int $id, string $username, bool $active): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, is_active, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :is_active, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'username' => $username,
            'password_hash' => password_hash('password-for-tests', PASSWORD_DEFAULT),
            'is_active' => $active ? 1 : 0,
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);
    }

    private function insertFeed(int $id, int $userId, string $name, bool $active): void
    {
        $statement = $this->pdo->prepare(
            'INSERT INTO feeds '
            . '(id, user_id, name, feed_url, is_active, last_fetch_status, created_at, updated_at) '
            . 'VALUES (:id, :user_id, :name, :feed_url, :is_active, :status, :created_at, :updated_at)'
        );
        $statement->execute([
            'id' => $id,
            'user_id' => $userId,
            'name' => $name,
            'feed_url' => 'https://feeds.test/' . $id . '.xml',
            'is_active' => $active ? 1 : 0,
            'status' => 'never',
            'created_at' => '2026-09-24T12:00:00Z',
            'updated_at' => '2026-09-24T12:00:00Z',
        ]);
    }
}
