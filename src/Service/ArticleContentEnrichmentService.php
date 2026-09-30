<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Repository\ArticleRepository;
use App\Storage\MediaStorage;
use Throwable;

final readonly class ArticleContentEnrichmentService
{
    public function __construct(
        private ArticleRepository $articles,
        private ArticlePageService $articlePages,
        private ArticleCoverDeduplicator $duplicates,
        private MediaStorage $mediaStorage,
        private ExternalHtmlTextSanitizer $sanitizer,
        private Clock $clock,
    ) {}

    /**
     * @param null|callable(int, int): void $progress
     * @return array{total: int, repaired: int, extracted: int, empty: int, failed: int}
     */
    public function run(?callable $progress = null): array
    {
        $repaired = $this->repairEncodedPageContent();
        $candidates = $this->articles->pendingPageContentCandidates();
        $summary = [
            'total' => count($candidates),
            'repaired' => $repaired,
            'extracted' => 0,
            'empty' => 0,
            'failed' => 0,
        ];
        /** @var array<string, list<array{id: int, user_id: int, url: string, image_path: string|null}>> $byUrl */
        $byUrl = [];
        foreach ($candidates as $candidate) {
            $byUrl[$candidate['url']][] = $candidate;
        }
        $done = 0;
        foreach ($byUrl as $url => $sameUrlCandidates) {
            $now = $this->clock->now()->format('Y-m-d\TH:i:s\Z');
            $path = parse_url($url, PHP_URL_PATH);
            if (!is_string($path) || $path === '' || $path === '/') {
                foreach ($sameUrlCandidates as $candidate) {
                    $this->articles->markPageContentChecked($candidate['id'], $candidate['user_id'], $now);
                    ++$summary['empty'];
                }
                $done += count($sameUrlCandidates);
                if ($progress !== null) {
                    $progress($done, $summary['total']);
                }
                continue;
            }
            try {
                $page = $this->articlePages->fetch($url);
                foreach ($sameUrlCandidates as $candidate) {
                    $userId = (int) $candidate['user_id'];
                    if ($page->content === null) {
                        $this->articles->markPageContentChecked($candidate['id'], $userId, $now);
                        ++$summary['empty'];
                    } else {
                        $this->articles->setPageContent(
                            $candidate['id'],
                            $userId,
                            $this->contentWithoutCoverRepetition($userId, $candidate, $page, $now),
                            $now,
                        );
                        ++$summary['extracted'];
                    }
                }
            } catch (Throwable) {
                $summary['failed'] += count($sameUrlCandidates);
            }
            $done += count($sameUrlCandidates);
            if ($progress !== null) {
                $progress($done, $summary['total']);
            }
            usleep(100_000);
        }

        return $summary;
    }

    /**
     * Contenu de page sans la répétition de l'illustration déjà stockée.
     * L'URL du visuel de page (og:image ou image candidate) est comparée
     * aux sources du contenu, puis les images encore inconnues le sont
     * octet pour octet ou perceptuellement, avec les octets du fichier
     * déjà téléchargé lors d'une synchronisation antérieure.
     *
     * @param array{id: int, user_id: int, url: string, image_path: string|null} $candidate
     */
    private function contentWithoutCoverRepetition(
        int $userId,
        array $candidate,
        \App\Model\ArticlePageData $page,
        string $now,
    ): string {
        $imagePath = $candidate['image_path'] ?? null;
        $content = (string) $page->content;
        $cover = null;
        if (is_string($imagePath) && $imagePath !== '') {
            $cover = $this->mediaStorage->read($userId, $imagePath);
        }
        $sources = $this->duplicates->imageSources($content);
        if ($sources === []) {
            return $content;
        }
        $duplicates = [];
        $knownSources = $sources;
        foreach ($page->imageCandidates as $imageCandidate) {
            if (($imageCandidate['min_side'] ?? 0) !== 1) {
                continue;
            }
            $duplicates = array_merge(
                $duplicates,
                $this->duplicates->sourcesMatchingUrl($imageCandidate['url'], $knownSources),
            );
        }
        if ($cover === null && $duplicates === []) {
            return $content;
        }
        $remaining = array_values(array_diff($knownSources, $duplicates));
        $duplicates = $this->duplicates->sourcesMatchingMedia($userId, $cover, $remaining, $duplicates);

        return $this->duplicates->stripSources($content, $duplicates);
    }

    private function repairEncodedPageContent(): int
    {
        $repaired = 0;
        foreach ($this->articles->encodedPageContentCandidates() as $candidate) {
            $content = $candidate['content'];
            for ($pass = 0; $pass < 3; ++$pass) {
                $decoded = html_entity_decode($content, ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if ($decoded === $content) {
                    break;
                }
                $content = $decoded;
            }
            $sanitized = $this->sanitizer->sanitize($content);
            if ($sanitized === null || $sanitized === $candidate['content']) {
                continue;
            }
            $now = $this->clock->now()->format('Y-m-d\TH:i:s\Z');
            if ($this->articles->setPageContent(
                $candidate['id'],
                $candidate['user_id'],
                $sanitized,
                $now,
            )) {
                ++$repaired;
            }
        }

        return $repaired;
    }
}
