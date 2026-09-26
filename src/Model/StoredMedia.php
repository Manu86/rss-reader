<?php

declare(strict_types=1);

namespace App\Model;

final readonly class StoredMedia
{
    public function __construct(
        public string $content,
        public string $contentType,
    ) {}
}
