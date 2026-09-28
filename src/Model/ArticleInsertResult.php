<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ArticleInsertResult
{
    /** @param list<array{id: int, image_url: string|null, article_url: string|null, metadata_fallback: bool}> $mediaCandidates */
    public function __construct(
        public int $count,
        public array $mediaCandidates,
    ) {}
}
