<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Repository\ArticleRepository;
use App\Storage\MediaStorage;

/**
 * Nettoie les contenus déjà stockés dont l'illustration de l'article
 * est répétée à l'intérieur du contenu. Les contenus nettoyés gardent
 * leur content_source et l'index de recherche est mis à jour.
 */
final readonly class ArticleDuplicateCoverService
{
    public function __construct(
        private ArticleRepository $articles,
        private MediaStorage $storage,
        private ArticleCoverDeduplicator $duplicates,
        private Clock $clock,
    ) {}

    /** @return array{checked: int, cleaned: int} */
    public function cleanup(): array
    {
        $checked = 0;
        $cleaned = 0;
        foreach ($this->articles->duplicateCoverCandidates() as $candidate) {
            ++$checked;
            $userId = (int) $candidate['user_id'];
            $cover = $this->storage->read($userId, (string) $candidate['image_path']);
            if ($cover === null) {
                continue;
            }
            $cleanedNow = false;
            foreach (['summary', 'content'] as $field) {
                $html = $candidate[$field];
                if (!is_string($html) || $html === '') {
                    continue;
                }
                $sources = $this->duplicates->imageSources($html);
                if ($sources === []) {
                    continue;
                }
                $duplicates = $this->duplicates->sourcesMatchingMedia($userId, $cover, $sources);
                if ($duplicates === []) {
                    continue;
                }
                $cleanedHtml = $this->duplicates->stripSources($html, $duplicates);
                if ($cleanedHtml === $html) {
                    continue;
                }
                $replaced = $field === 'summary'
                    ? $this->articles->replaceSummary(
                        (int) $candidate['id'],
                        $userId,
                        $cleanedHtml,
                        $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
                    )
                    : $this->articles->replaceContent(
                        (int) $candidate['id'],
                        $userId,
                        $cleanedHtml,
                        $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
                    );
                if ($replaced) {
                    $cleanedNow = true;
                }
            }
            if ($cleanedNow) {
                ++$cleaned;
            }
        }

        return ['checked' => $checked, 'cleaned' => $cleaned];
    }
}
