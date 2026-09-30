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
            $sources = $this->duplicates->imageSources((string) $candidate['content']);
            if ($sources === []) {
                continue;
            }
            $duplicates = $this->duplicates->sourcesMatchingMedia($userId, $cover, $sources);
            if ($duplicates === []) {
                continue;
            }
            $cleanedContent = $this->duplicates->stripSources((string) $candidate['content'], $duplicates);
            if ($cleanedContent === (string) $candidate['content']) {
                continue;
            }
            if ($this->articles->replaceContent(
                (int) $candidate['id'],
                $userId,
                $cleanedContent,
                $this->clock->now()->format('Y-m-d\TH:i:s\Z'),
            )) {
                ++$cleaned;
            }
        }

        return ['checked' => $checked, 'cleaned' => $cleaned];
    }
}
