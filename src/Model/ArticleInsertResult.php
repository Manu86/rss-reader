<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ArticleInsertResult
{
    /**
     * @param list<array{id: int, image_url: string|null, article_url: string|null, metadata_fallback: bool}> $mediaCandidates
     * @param list<array{id: int, article_url: string, content_fallback: bool, image_fallback: bool}> $pageCandidates
     */
    public function __construct(
        public int $count,
        public array $mediaCandidates,
        public array $pageCandidates = [],
    ) {}
}
