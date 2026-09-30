<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Database\TransactionManager;
use App\Exception\ApiException;
use App\Exception\ConflictException;
use App\Exception\FeedBusyException;
use App\Model\ArticleInsertResult;
use App\Model\Feed;
use App\Model\ParsedFeed;
use App\Repository\ArticleRepository;
use App\Repository\FeedRepository;
use PDOException;
use Throwable;

final readonly class FeedSynchronizationService implements FeedRefresher
{
    /**
     * Le plafond borne le temps d'une synchronisation face aux pages
     * distantes. Quinze tentatives rattrapent un flux qui publie un lot
     * d'un coup (l'ajout d'un flux importe jusqu'à ~100 articles) en une
     * poignée de synchronisations, tout en gardant la passe de pages sous
     * quelques dizaines de secondes.
     */
    private const MAX_ARTICLE_PAGES_PER_SYNCHRONIZATION = 15;

    public function __construct(
        private FeedRepository $feeds,
        private ArticleRepository $articles,
        private FeedFetcher $fetcher,
        private TransactionManager $transactions,
        private Clock $clock,
        private RemoteMediaService $media,
        private ArticlePageService $articlePages,
        private ArticleCoverDeduplicator $duplicates,
        private FeedRefreshLock $locks,
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
        // Le verrou est pris avant d'enregistrer la tentative : un flux occupé
        // ne doit laisser aucune trace de synchronisation, et surtout ne doit
        // jamais écraser le validateur HTTP écrit par la synchronisation en
        // cours, qui a elle-même importé les articles correspondants.
        if (!$this->locks->acquire($feed->id)) {
            throw FeedBusyException::alreadyRefreshing();
        }

        try {
            return $this->synchronize($feed);
        } finally {
            $this->locks->release($feed->id);
        }
    }

    /** @return array{feed: Feed, imported_articles: int, not_modified: bool} */
    private function synchronize(Feed $feed): array
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

        $storedFeedCoverUrls = [];
        foreach ($inserted->mediaCandidates as $candidate) {
            try {
                if ($candidate['image_url'] === null) {
                    continue;
                }
                $key = $this->media->download($feed->userId, $candidate['image_url']);
                $stored = $this->articles->setImagePath($candidate['id'], $feed->userId, $key, $now);
                if (!$stored) {
                    $this->media->discard($feed->userId, $key);
                }
                if ($stored) {
                    $storedFeedCoverUrls[$candidate['id']] = $candidate['image_url'];
                    $this->removeInvisibleCoverRepetition($feed->userId, $candidate['id'], $candidate['image_url'], $now);
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

        foreach (array_slice($inserted->pageCandidates, 0, self::MAX_ARTICLE_PAGES_PER_SYNCHRONIZATION) as $candidate) {
            try {
                $page = $this->articlePages->fetch($candidate['article_url']);
                $duplicates = [];
                if ($candidate['image_fallback']) {
                    $this->articles->markImageMetadataChecked($candidate['id'], $feed->userId, $now);
                    try {
                        $cover = $this->media->downloadFirst($feed->userId, $page->imageCandidates);
                        if (!$this->articles->setImagePath($candidate['id'], $feed->userId, $cover['key'], $now)) {
                            $this->media->discard($feed->userId, $cover['key']);
                        }
                        $contentSources = $page->content === null
                            ? []
                            : $this->duplicates->imageSources($page->content);
                        $duplicates = $this->duplicates->sourcesMatchingUrl($cover['url'], $contentSources);
                        // Les gabarits publient la même illustration sous
                        // plusieurs résolutions dont l'URL diffère : la
                        // comparaison perceptuelle les reconnaît au prix de
                        // quelques téléchargements de contrôle, bornés par
                        // la liste des candidates de la page.
                        $duplicates = $this->duplicates->sourcesMatchingStoredMedia(
                            $feed->userId,
                            $cover['key'],
                            array_values(array_diff($contentSources, $duplicates)),
                            $duplicates,
                        );
                    } catch (Throwable) {
                        error_log(sprintf(
                            'Article image import failed [user:%d feed:%d article:%d]',
                            $feed->userId,
                            $feed->id,
                            $candidate['id'],
                        ));
                    }
                }
                $feedCoverUrl = $storedFeedCoverUrls[$candidate['id']] ?? null;
                if ($feedCoverUrl !== null) {
                    $duplicates = array_merge($duplicates, $this->duplicates->sourcesMatchingUrl(
                        $feedCoverUrl,
                        $page->content === null ? [] : $this->duplicates->imageSources($page->content),
                    ));
                }
                if ($candidate['content_fallback']) {
                    if ($page->content === null) {
                        $this->articles->markPageContentChecked($candidate['id'], $feed->userId, $now);
                    } else {
                        $this->articles->setPageContent(
                            $candidate['id'],
                            $feed->userId,
                            $this->duplicates->stripSources($page->content, $duplicates),
                            $now,
                        );
                    }
                }
            } catch (Throwable) {
                error_log(sprintf(
                    'Article page import failed [user:%d feed:%d article:%d]',
                    $feed->userId,
                    $feed->id,
                    $candidate['id'],
                ));
            }
        }
    }

    /**
     * Le visuel du flux n'est pas répété dans le contenu du même article :
     * le contenu de flux qui l'embarque est nettoyé après le téléchargement
     * du visuel, sans changer sa source ni les marques de vérification.
     */
    private function removeInvisibleCoverRepetition(
        int $userId,
        int $articleId,
        string $coverUrl,
        string $now,
    ): void {
        $content = $this->articles->findContentOwned($articleId, $userId);
        if ($content === null) {
            return;
        }
        $duplicates = $this->duplicates->sourcesMatchingUrl(
            $coverUrl,
            $this->duplicates->imageSources($content),
        );
        if ($duplicates === []) {
            return;
        }
        $cleaned = $this->duplicates->stripSources($content, $duplicates);
        if ($cleaned !== $content) {
            $this->articles->replaceContent($articleId, $userId, $cleaned, $now);
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
