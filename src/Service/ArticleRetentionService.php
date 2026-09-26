<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Repository\ArticleRetentionRepository;
use DateInterval;

final readonly class ArticleRetentionService
{
    private const BATCH_SIZE = 500;

    public function __construct(
        private ArticleRetentionRepository $articles,
        private MediaCleanupService $mediaCleanup,
        private Clock $clock,
    ) {}

    public function cleanup(): int
    {
        $cutoff = $this->clock->now()
            ->sub(new DateInterval('P1Y'))
            ->format('Y-m-d\TH:i:s\Z');
        $total = 0;

        do {
            $deleted = $this->articles->deleteExpiredBatch($cutoff, self::BATCH_SIZE);
            $total += count($deleted);
            /** @var array<int, list<string>> $mediaByUser */
            $mediaByUser = [];
            foreach ($deleted as $article) {
                if ($article['image_path'] !== null) {
                    $mediaByUser[$article['user_id']][] = $article['image_path'];
                }
            }
            foreach ($mediaByUser as $userId => $paths) {
                $this->mediaCleanup->removeUnreferenced($userId, $paths);
            }
        } while (count($deleted) === self::BATCH_SIZE);

        return $total;
    }
}
