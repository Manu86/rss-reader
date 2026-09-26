<?php

declare(strict_types=1);

namespace App\Model;

final readonly class FeedFetchResult
{
    public function __construct(
        public bool $notModified,
        public ?ParsedFeed $feed,
        public ?string $etag,
        public ?string $lastModified,
    ) {}
}
