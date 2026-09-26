<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Model\Article;
use App\Model\ArticleListCriteria;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;

/**
 * Recommendations are computed from the user's own reading history only:
 * keywords of recent favorite articles matched through FTS, plus tag and
 * category affinity. They never use other users' data and are scoring
 * only (no AI, no external service).
 */
final readonly class RecommendationService
{
    private const FAVORITE_SIGNAL_LIMIT = 20;
    private const CANDIDATE_LIMIT = 60;
    private const RECOMMENDATION_LIMIT = 8;

    public function __construct(
        private ArticleRepository $articles,
        private CategoryRepository $categories,
    ) {}

    /** @return list<Article> */
    public function forUser(int $userId, ?int $categoryId = null, bool $uncategorized = false): array
    {
        if ($categoryId !== null && !$this->categories->existsOwned($categoryId, $userId)) {
            throw new NotFoundException('Catégorie introuvable.');
        }
        $signals = $this->articles->listFavoriteSignals($userId, self::FAVORITE_SIGNAL_LIMIT);
        if (count($signals) === 0) {
            return [];
        }
        $terms = $this->terms($signals);
        if (count($terms) === 0) {
            return [];
        }
        $candidates = $this->articles->searchOwned(
            $userId,
            new ArticleListCriteria('unread', $categoryId, $uncategorized, null, 1, self::CANDIDATE_LIMIT),
            $this->ftsQuery($terms),
        );
        if (count($candidates) === 0) {
            return [];
        }

        return $this->rank($candidates, $signals);
    }

    /**
     * @param list<array{id: int, title: string, tags: list<string>, category_id: int|null}> $signals
     * @return list<string>
     */
    private function terms(array $signals): array
    {
        $terms = [];
        foreach ($signals as $signal) {
            $title = is_string($signal['title'] ?? null) ? $signal['title'] : '';
            $matched = preg_match_all('/[\p{L}\p{N}_]+/u', $title, $matches);
            if (is_int($matched) && $matched > 0) {
                foreach ($matches[0] as $term) {
                    if (mb_strlen($term, 'UTF-8') <= 32 && !in_array($term, $terms, true)) {
                        $terms[] = $term;
                        if (count($terms) >= 15) {
                            return $terms;
                        }
                    }
                }
            }
            foreach (is_array($signal['tags'] ?? null) ? $signal['tags'] : [] as $tag) {
                if (is_string($tag) && !in_array($tag, $terms, true)) {
                    $terms[] = $tag;
                    if (count($terms) >= 20) {
                        return $terms;
                    }
                }
            }
        }

        return $terms;
    }

    /** @param list<string> $terms */
    private function ftsQuery(array $terms): string
    {
        return implode(' OR ', array_map(
            static fn(string $term): string => '"' . $term . '"*',
            $terms,
        ));
    }

    /**
     * @param list<Article> $candidates
     * @param list<array{id: int, title: string, tags: list<string>, category_id: int|null}> $signals
     * @return list<Article>
     */
    private function rank(array $candidates, array $signals): array
    {
        $favoriteTags = [];
        $favoriteCategories = [];
        foreach ($signals as $signal) {
            if ($signal['category_id'] !== null) {
                $favoriteCategories[] = $signal['category_id'];
            }
            foreach (is_array($signal['tags'] ?? null) ? $signal['tags'] : [] as $tag) {
                if (is_string($tag)) {
                    $favoriteTags[] = $tag;
                }
            }
        }

        $scored = [];
        foreach ($candidates as $article) {
            if ($article->favorite) {
                continue;
            }
            $tags = is_array($article->tags) ? $article->tags : [];
            $tagScore = 0;
            if (count($favoriteTags) > 0 && count($tags) > 0) {
                $tagScore = 2 * count(array_intersect($tags, $favoriteTags));
            }
            $categoryScore = $article->categoryId !== null
                && in_array($article->categoryId, $favoriteCategories, true) ? 2 : 0;
            $score = 1 + $tagScore + $categoryScore;
            $scored[] = ['score' => $score, 'article' => $article];
        }
        usort($scored, static function (array $left, array $right): int {
            $byScore = $right['score'] <=> $left['score'];
            if ($byScore !== 0) {
                return $byScore;
            }
            $leftDate = $left['article']->publishedAt ?? $left['article']->discoveredAt;
            $rightDate = $right['article']->publishedAt ?? $right['article']->discoveredAt;

            return $rightDate <=> $leftDate;
        });

        $selected = [];
        foreach ($scored as $entry) {
            $selected[] = $entry['article'];
            if (count($selected) >= self::RECOMMENDATION_LIMIT) {
                break;
            }
        }

        return $selected;
    }
}
