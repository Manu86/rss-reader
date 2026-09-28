<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Article;

interface RecommendationProvider
{
    /**
     * @param int|null $limit nombre maximal d'articles renvoyés, ou null pour
     *                        la limite de la vue web. Le digest email impose sa
     *                        propre limite : un message qui double de volume
     *                        fait Penaliser l'expedition.
     * @return list<Article>
     */
    public function forUser(
        int $userId,
        ?int $categoryId = null,
        bool $uncategorized = false,
        ?int $limit = null,
    ): array;
}
