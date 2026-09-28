<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Article;
use App\Model\RecommendationEmailRecipient;

interface RecommendationDigestMailer
{
    /** @param list<Article> $articles */
    public function send(RecommendationEmailRecipient $recipient, array $articles): void;
}
