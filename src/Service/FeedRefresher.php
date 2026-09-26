<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Feed;

interface FeedRefresher
{
    /** @return array{feed: Feed, imported_articles: int, not_modified: bool} */
    public function refresh(Feed $feed): array;
}
