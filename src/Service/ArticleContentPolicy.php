<?php

declare(strict_types=1);

namespace App\Service;

final class ArticleContentPolicy
{
    public const MINIMUM_VISIBLE_CHARACTERS = 200;

    public static function visibleLength(?string $content): int
    {
        if ($content === null || trim($content) === '') {
            return 0;
        }

        $text = html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return mb_strlen(trim($text), 'UTF-8');
    }

    public static function isSubstantial(?string $content): bool
    {
        return self::visibleLength($content) >= self::MINIMUM_VISIBLE_CHARACTERS;
    }
}
