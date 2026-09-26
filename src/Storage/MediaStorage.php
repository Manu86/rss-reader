<?php

declare(strict_types=1);

namespace App\Storage;

use App\Model\StoredMedia;

interface MediaStorage
{
    public function store(int $userId, string $content, string $extension): string;

    public function read(int $userId, string $key): ?StoredMedia;

    public function delete(int $userId, string $key): void;
}
