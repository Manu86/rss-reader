<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ParsedArticle
{
    public function __construct(
        public ?string $guid,
        public ?string $guidHash,
        public string $title,
        public ?string $url,
        public ?string $author,
        public ?string $publishedAt,
        public ?string $summary,
        public ?string $content,
        public string $deduplicationHash,
        public ?string $imageUrl,
        /** @var list<string> */
        public array $tags = [],
    ) {}
}
