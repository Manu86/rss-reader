<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Model\StoredMedia;
use App\Storage\MediaStorage;

final class MemoryMediaStorage implements MediaStorage
{
    /** @var array<string, StoredMedia> */
    public array $media = [];

    private int $sequence = 0;

    public function store(int $userId, string $content, string $extension): string
    {
        $key = sprintf('u%d/test/%032x.%s', $userId, ++$this->sequence, $extension);
        $contentTypes = [
            'jpg' => 'image/jpeg',
            'png' => 'image/png',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'ico' => 'image/x-icon',
        ];
        $this->media[$key] = new StoredMedia($content, $contentTypes[$extension]);

        return $key;
    }

    public function read(int $userId, string $key): ?StoredMedia
    {
        if (!str_starts_with($key, 'u' . $userId . '/')) {
            return null;
        }

        return $this->media[$key] ?? null;
    }

    public function delete(int $userId, string $key): void
    {
        if (str_starts_with($key, 'u' . $userId . '/')) {
            unset($this->media[$key]);
        }
    }
}
