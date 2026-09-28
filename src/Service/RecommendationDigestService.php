<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Model\RecommendationEmailRecipient;
use App\Repository\RecommendationEmailRepository;
use DateTimeImmutable;
use DateTimeZone;
use Throwable;

final readonly class RecommendationDigestService
{
    private const TIMEZONE = 'Europe/Paris';
    private const SEND_HOUR = 8;
    /**
     * Le digest reste plafonne a 24 articles alors que la vue web en propose 48.
     * Un message qui double de volume est plus expose aux filtres antispam, et
     * la liste garde de toute facon le nombre d'articles a lire au daily.
     */
    private const ARTICLE_LIMIT = 24;

    public function __construct(
        private RecommendationEmailRepository $recipients,
        private RecommendationProvider $recommendations,
        private ?RecommendationDigestMailer $mailer,
        private Clock $clock,
    ) {}

    /** @return array{configured: bool, total: int, due: int, sent: int, empty: int, failed: int} */
    public function run(): array
    {
        $summary = [
            'configured' => $this->mailer !== null,
            'total' => 0,
            'due' => 0,
            'sent' => 0,
            'empty' => 0,
            'failed' => 0,
        ];
        if ($this->mailer === null) {
            return $summary;
        }

        $now = $this->clock->now();
        foreach ($this->recipients->listEnabledRecipients() as $recipient) {
            ++$summary['total'];
            if (!$this->isDue($recipient, $now)) {
                continue;
            }
            ++$summary['due'];
            try {
                $articles = $this->recommendations->forUser(
                    $recipient->userId,
                    null,
                    false,
                    self::ARTICLE_LIMIT,
                );
                if ($articles === []) {
                    ++$summary['empty'];
                    continue;
                }
                $this->mailer->send($recipient, $articles);
                $this->recipients->markSent(
                    $recipient->userId,
                    $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z'),
                );
                ++$summary['sent'];
            } catch (Throwable) {
                ++$summary['failed'];
                error_log(sprintf(
                    'Recommendation email delivery failed [user:%d]',
                    $recipient->userId,
                ));
            }
        }

        return $summary;
    }

    private function isDue(RecommendationEmailRecipient $recipient, DateTimeImmutable $now): bool
    {
        $timezone = new DateTimeZone(self::TIMEZONE);
        $localNow = $now->setTimezone($timezone);
        if ((int) $localNow->format('G') < self::SEND_HOUR) {
            return false;
        }
        $currentPeriod = $this->period($recipient->frequency, $localNow);
        if ($currentPeriod === null) {
            return false;
        }
        if ($recipient->lastSentAt === null) {
            return true;
        }
        try {
            $lastSent = (new DateTimeImmutable($recipient->lastSentAt))->setTimezone($timezone);
        } catch (Throwable) {
            return true;
        }

        return $this->period($recipient->frequency, $lastSent) !== $currentPeriod;
    }

    private function period(string $frequency, DateTimeImmutable $date): ?string
    {
        return match ($frequency) {
            'daily' => $date->format('Y-m-d'),
            'weekly' => $date->format('o-W'),
            'monthly' => $date->format('Y-m'),
            default => null,
        };
    }
}
