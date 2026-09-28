<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Database\ConnectionFactory;
use App\Database\Migrator;
use App\Model\Article;
use App\Model\RecommendationEmailRecipient;
use App\Repository\RecommendationEmailRepository;
use App\Service\RecommendationDigestMailer;
use App\Service\RecommendationDigestService;
use App\Service\RecommendationProvider;
use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Tests\Support\FrozenClock;

final class RecommendationDigestServiceTest extends TestCase
{
    private PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = ConnectionFactory::createMemory();
        (new Migrator($this->pdo, dirname(__DIR__, 2) . '/migrations'))->migrate();
    }

    public function testDueDailyWeeklyAndMonthlyDigestsAreSentOnlyOncePerPeriod(): void
    {
        $this->insertRecipient(1, 'daily', '2026-09-27T06:00:00Z');
        $this->insertRecipient(2, 'weekly', '2026-09-21T06:00:00Z');
        $this->insertRecipient(3, 'monthly', '2026-08-01T06:00:00Z');
        $provider = $this->provider([1 => [$this->article(11)], 2 => [$this->article(12)], 3 => [$this->article(13)]]);
        $mailer = $this->mailer();
        $service = new RecommendationDigestService(
            new RecommendationEmailRepository($this->pdo),
            $provider,
            $mailer,
            new FrozenClock(new DateTimeImmutable('2026-09-28T06:00:00Z')),
        );

        self::assertSame([
            'configured' => true,
            'total' => 3,
            'due' => 3,
            'sent' => 3,
            'empty' => 0,
            'failed' => 0,
        ], $service->run());
        self::assertSame([1, 2, 3], $mailer->sent);
        self::assertSame('2026-09-28T06:00:00Z', $this->lastSentAt(1));

        self::assertSame(0, $service->run()['due']);
        self::assertSame([1, 2, 3], $mailer->sent);
    }

    public function testNothingIsSentBeforeEightInParis(): void
    {
        $this->insertRecipient(1, 'daily', null);
        $mailer = $this->mailer();
        $service = new RecommendationDigestService(
            new RecommendationEmailRepository($this->pdo),
            $this->provider([1 => [$this->article(11)]]),
            $mailer,
            new FrozenClock(new DateTimeImmutable('2026-09-28T05:59:00Z')),
        );

        self::assertSame(0, $service->run()['due']);
        self::assertSame([], $mailer->sent);
    }

    public function testEmptyRecommendationsRemainEligibleForALaterRun(): void
    {
        $this->insertRecipient(1, 'daily', null);
        $service = new RecommendationDigestService(
            new RecommendationEmailRepository($this->pdo),
            $this->provider([1 => []]),
            $this->mailer(),
            new FrozenClock(new DateTimeImmutable('2026-09-28T06:00:00Z')),
        );

        $summary = $service->run();

        self::assertSame(1, $summary['empty']);
        self::assertSame(0, $summary['sent']);
        self::assertNull($this->lastSentAt(1));
    }

    public function testDeliveryFailureDoesNotMarkTheDigestAsSent(): void
    {
        $this->insertRecipient(1, 'daily', null);
        $mailer = new class implements RecommendationDigestMailer {
            public function send(RecommendationEmailRecipient $recipient, array $articles): void
            {
                throw new \RuntimeException('SMTP indisponible');
            }
        };
        $service = new RecommendationDigestService(
            new RecommendationEmailRepository($this->pdo),
            $this->provider([1 => [$this->article(11)]]),
            $mailer,
            new FrozenClock(new DateTimeImmutable('2026-09-28T06:00:00Z')),
        );

        self::assertSame(1, $service->run()['failed']);
        self::assertNull($this->lastSentAt(1));
    }

    public function testTheDigestAsksForAtMostTwentyFourArticles(): void
    {
        $this->insertRecipient(1, 'daily', '2026-09-27T06:00:00Z');
        $provider = $this->provider([1 => array_map(
            fn(int $id): Article => $this->article($id),
            range(1, 40),
        )]);
        $mailer = $this->mailer();
        $service = new RecommendationDigestService(
            new RecommendationEmailRepository($this->pdo),
            $provider,
            $mailer,
            new FrozenClock(new DateTimeImmutable('2026-09-28T06:00:00Z')),
        );

        self::assertSame(1, $service->run()['sent']);
        self::assertSame([24], $provider->limits);
        self::assertCount(24, $mailer->deliveries[0][1]);
    }

    public function testMissingMailerDisablesDeliveryWithoutReadingRecipients(): void
    {
        $this->insertRecipient(1, 'daily', null);
        $service = new RecommendationDigestService(
            new RecommendationEmailRepository($this->pdo),
            $this->provider([1 => [$this->article(11)]]),
            null,
            new FrozenClock(new DateTimeImmutable('2026-09-28T06:00:00Z')),
        );

        self::assertSame([
            'configured' => false,
            'total' => 0,
            'due' => 0,
            'sent' => 0,
            'empty' => 0,
            'failed' => 0,
        ], $service->run());
    }

    private function insertRecipient(int $id, string $frequency, ?string $lastSentAt): void
    {
        $user = $this->pdo->prepare(
            'INSERT INTO users (id, username, password_hash, email, created_at, updated_at) '
            . 'VALUES (:id, :username, :password_hash, :email, :created_at, :updated_at)'
        );
        $user->execute([
            'id' => $id,
            'username' => 'user' . $id,
            'password_hash' => password_hash('password-for-tests', PASSWORD_DEFAULT),
            'email' => 'user' . $id . '@example.org',
            'created_at' => '2026-09-01T00:00:00Z',
            'updated_at' => '2026-09-01T00:00:00Z',
        ]);
        $settings = $this->pdo->prepare(
            'INSERT INTO user_settings '
            . '(user_id, articles_per_page, theme, recommendation_email_frequency, '
            . 'recommendation_email_last_sent_at, created_at, updated_at) '
            . 'VALUES (:user_id, 25, :theme, :frequency, :last_sent_at, :created_at, :updated_at)'
        );
        $settings->execute([
            'user_id' => $id,
            'theme' => 'light',
            'frequency' => $frequency,
            'last_sent_at' => $lastSentAt,
            'created_at' => '2026-09-01T00:00:00Z',
            'updated_at' => '2026-09-01T00:00:00Z',
        ]);
    }

    /**
     * Le double respecte la limite demandee, comme le vrai service, et expose
     * les limites recues pour que les tests puissent les verifier.
     *
     * @param array<int, list<Article>> $articles
     * @return RecommendationProvider&object{limits: list<int|null>}
     */
    private function provider(array $articles): object
    {
        return new class ($articles) implements RecommendationProvider {
            /** @var list<int|null> */
            public array $limits = [];

            /** @param array<int, list<Article>> $articles */
            public function __construct(private array $articles) {}

            public function forUser(
                int $userId,
                ?int $categoryId = null,
                bool $uncategorized = false,
                ?int $limit = null,
            ): array {
                $this->limits[] = $limit;
                $articles = $this->articles[$userId] ?? [];

                return $limit === null ? $articles : array_slice($articles, 0, $limit);
            }
        };
    }

    private function mailer(): RecordingDigestMailer
    {
        return new RecordingDigestMailer();
    }

    private function article(int $id): Article
    {
        return new Article(
            $id,
            10,
            'Source',
            false,
            null,
            null,
            'Article ' . $id,
            'https://example.org/' . $id,
            null,
            '2026-09-28T05:00:00Z',
            '2026-09-28T05:00:00Z',
            null,
            null,
        );
    }

    private function lastSentAt(int $userId): ?string
    {
        $statement = $this->pdo->prepare(
            'SELECT recommendation_email_last_sent_at FROM user_settings WHERE user_id = :user_id'
        );
        $statement->execute(['user_id' => $userId]);
        $value = $statement->fetchColumn();

        return $value === false || $value === null ? null : (string) $value;
    }
}

final class RecordingDigestMailer implements RecommendationDigestMailer
{
    /** @var list<int> */
    public array $sent = [];

    /** @var list<array{int, list<Article>}> */
    public array $deliveries = [];

    public function send(RecommendationEmailRecipient $recipient, array $articles): void
    {
        $this->sent[] = $recipient->userId;
        $this->deliveries[] = [$recipient->userId, $articles];
    }
}
