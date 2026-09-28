<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Article;

interface RecommendationProvider
{
    /** @return list<Article> */
    public function forUser(int $userId, ?int $categoryId = null, bool $uncategorized = false): array;
}
