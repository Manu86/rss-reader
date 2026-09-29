<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ArticlePageData
{
    /** @param list<array{url: string, min_side: int}> $imageCandidates */
    public function __construct(
        public ?string $content,
        public array $imageCandidates,
    ) {}
}
