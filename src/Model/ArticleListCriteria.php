<?php

declare(strict_types=1);

namespace App\Model;

final readonly class ArticleListCriteria
{
    public function __construct(
        public string $filter,
        public ?int $categoryId,
        public bool $uncategorized,
        public ?int $feedId,
        public int $page,
        public int $perPage,
    ) {}
}
