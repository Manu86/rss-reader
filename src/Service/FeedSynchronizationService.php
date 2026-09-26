<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Database\TransactionManager;
use App\Exception\ApiException;
use App\Exception\ConflictException;
use App\Model\ArticleInsertResult;
use App\Model\Feed;
use App\Model\ParsedFeed;
use App\Repository\ArticleRepository;
use App\Repository\FeedRepository;
use PDOException;
use Throwable;

final readonly class FeedSynchronizationService implements FeedRefresher
{
    public function __construct(
        private FeedRepository $feeds,
        private ArticleRepository $articles,
        private FeedFetcher $fetcher,
        private TransactionManager $transactions,
        private Clock $clock,
        private RemoteMediaService $media,
    ) {}

    public function create(
        int $userId,
        ?int $categoryId,
        ?string $customName,
        string $feedUrl,
    ): Feed {
        $result = $this->fetcher->fetch($feedUrl);
        $parsed = $result->feed;
        if (!$parsed instanceof ParsedFeed) {
            throw new \RuntimeException('Le résultat initial du flux est absent.');
        }
        $now = $this->now();

        try {
            $created = $this->transactions->run(function () use (
                $userId,
                $categoryId,
                $customName,
                $feedUrl,
                $result,
                $parsed,
                $now,
            ): array {
                $feed = $this->feeds->createImported(
                    $userId,
                    $categoryId,
                    $customName ?? mb_substr($parsed->title, 0, 200, 'UTF-8'),
                    $feedUrl,
                    $parsed->siteUrl,
                    $result->etag,
                    $result->lastModified,
                    $now,
                );
                $inserted = $this->articles->insertNew($userId, $feed->id, $parsed->articles, $now);

                $feed = $this->feeds->markSuccess(
                    $feed,
                    $parsed->siteUrl,
                    $result->etag,
                    $result->lastModified,
                    $now,
                );

                return ['feed' => $feed, 'inserted' => $inserted];
            });
            $this->attachMedia($created['feed'], $parsed, $created['inserted'], $now);

            return $this->feeds->findOwned($created['feed']->id, $userId) ?? $created['feed'];
        } catch (PDOException $exception) {
            if ((string) $exception->getCode() === '23000'
                && str_contains($exception->getMessage(), 'feeds.user_id, feeds.feed_url')) {
                throw new ConflictException('FEED_ALREADY_EXISTS', 'Cet abonnement existe déjà.');
            }
            throw $exception;
        }
    }

    /** @return array{feed: Feed, imported_articles: int, not_modified: bool} */
    public function refresh(Feed $feed): array
    {
        $attemptedAt = $this->now();
        $this->feeds->markAttempt($feed, $attemptedAt);
        try {
            $result = $this->fetcher->fetch($feed->feedUrl, $feed->etag, $feed->lastModified);
        } catch (ApiException $exception) {
            $this->feeds->markFailure($feed, $exception->getMessage(), $attemptedAt);
            throw $exception;
        } catch (Throwable $exception) {
            $this->feeds->markFailure($feed, 'Une erreur interne a interrompu la synchronisation.', $attemptedAt);
            throw $exception;
        }

        $synchronized = $this->transactions->run(function () use ($feed, $result, $attemptedAt): array {
            $inserted = new ArticleInsertResult(0, []);
            $siteUrl = null;
            if ($result->feed instanceof ParsedFeed) {
                $siteUrl = $result->feed->siteUrl;
                $inserted = $this->articles->insertNew(
                    $feed->userId,
                    $feed->id,
                    $result->feed->articles,
                    $attemptedAt,
                );
            }
            $updated = $this->feeds->markSuccess(
                $feed,
                $siteUrl,
                $result->etag,
                $result->lastModified,
                $attemptedAt,
            );

            return [
                'feed' => $updated,
                'inserted' => $inserted,
                'not_modified' => $result->notModified,
            ];
        });
        if ($result->feed instanceof ParsedFeed) {
            $this->attachMedia($synchronized['feed'], $result->feed, $synchronized['inserted'], $attemptedAt);
        }
        $updated = $this->feeds->findOwned($feed->id, $feed->userId) ?? $synchronized['feed'];

        return [
            'feed' => $updated,
            'imported_articles' => $synchronized['inserted']->count,
            'not_modified' => $synchronized['not_modified'],
        ];
    }

    private function attachMedia(
        Feed $feed,
        ParsedFeed $parsed,
        ArticleInsertResult $inserted,
        string $now,
    ): void {
        if ($feed->faviconPath === null && $parsed->faviconUrl !== null) {
            try {
                $key = $this->media->download($feed->userId, $parsed->faviconUrl);
                if (!$this->feeds->setFaviconPath($feed, $key, $now)) {
                    $this->media->discard($feed->userId, $key);
                }
            } catch (Throwable) {
                error_log(sprintf('Feed favicon import failed [user:%d feed:%d]', $feed->userId, $feed->id));
            }
        }

        foreach ($inserted->mediaCandidates as $candidate) {
            try {
                $key = $this->media->download($feed->userId, $candidate['image_url']);
                if (!$this->articles->setImagePath($candidate['id'], $feed->userId, $key, $now)) {
                    $this->media->discard($feed->userId, $key);
                }
            } catch (Throwable) {
                error_log(sprintf(
                    'Article image import failed [user:%d feed:%d article:%d]',
                    $feed->userId,
                    $feed->id,
                    $candidate['id'],
                ));
            }
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
