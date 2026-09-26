<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ParsedFeed
{
    /** @param list<ParsedArticle> $articles */
    public function __construct(
        public string $title,
        public ?string $siteUrl,
        public array $articles,
        public ?string $faviconUrl,
    ) {}
}
