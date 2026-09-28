<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Article;
use App\Model\StoredMedia;

interface RecommendationImageProvider
{
    public function forArticle(int $userId, Article $article): ?StoredMedia;
}
