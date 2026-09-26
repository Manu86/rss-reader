<?php

declare(strict_types=1);

namespace App\Service;

use App\Repository\MediaReferenceRepository;
use App\Storage\MediaStorage;
use Throwable;

final readonly class MediaCleanupService
{
    public function __construct(
        private MediaReferenceRepository $references,
        private MediaStorage $storage,
    ) {}

    /** @return list<string> */
    public function pathsForFeed(int $feedId, int $userId): array
    {
        return $this->references->listForOwnedFeed($feedId, $userId);
    }

    /** @param list<string> $paths */
    public function removeUnreferenced(int $userId, array $paths): void
    {
        foreach (array_unique($paths) as $path) {
            if ($this->references->isReferenced($userId, $path)) {
                continue;
            }
            try {
                $this->storage->delete($userId, $path);
            } catch (Throwable) {
                error_log(sprintf('Unable to remove orphan media [user:%d]', $userId));
            }
        }
    }
}
