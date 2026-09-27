<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\NotFoundException;
use App\Model\Article;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use Random\Randomizer;

/**
 * Recommendations are computed from the user's own reading history only:
 * keywords of recent favorite articles matched through FTS, plus tag and
 * category affinity. They never use other users' data and are scoring
 * only (no AI, no external service). A small bounded jitter breaks score
 * ties so the same articles do not stay pinned at the top of the view.
 */
final readonly class RecommendationService
{
    private const FAVORITE_SIGNAL_LIMIT = 20;
    private const CANDIDATE_LIMIT = 60;
    private const RECOMMENDATION_LIMIT = 24;
    private const TITLE_TERM_LIMIT_PER_FAVORITE = 6;
    private const TITLE_TERM_LIMIT = 30;
    private const TAG_TERM_LIMIT = 30;
    private const MIN_TERM_LENGTH = 3;
    private const MAX_TERM_LENGTH = 32;
    private const RELEVANCE_WEIGHT = 3.0;
    private const TAG_WEIGHT = 2;
    private const CATEGORY_WEIGHT = 2;
    /**
     * Amplitude du bruit de score. Volontairement inférieure au poids de la
     * pertinence (3) : deux articles ne s'échangent que si leurs scores de
     * base sont proches, donc le meilleur match reste en tête.
     */
    private const JITTER = 0.6;

    /**
     * Mots grammaticaux français : sans ce filtre, ils matchent presque tous
     * les articles et rendent la sélection FTS indifférenciée.
     */
    private const STOP_WORDS = [
        'alors', 'après', 'au', 'aucun', 'aucune', 'aujourd', 'aussi', 'autre', 'autres',
        'avec', 'avoir', 'beaucoup', 'bien', 'car', 'ce', 'cela', 'ces', 'cet', 'cette',
        'chaque', 'chez', 'comme', 'comment', 'dans', 'depuis', 'des', 'deux', 'devrait',
        'dit', 'donc', 'dont', 'du', 'elle', 'elles', 'encore', 'entre', 'est', 'et', 'été',
        'être', 'fait', 'faire', 'fois', 'font', 'hors', 'ici', 'ils', 'juste', 'la', 'là',
        'le', 'les', 'leur', 'leurs', 'lui', 'ma', 'mais', 'malgré', 'me', 'même', 'mes',
        'moins', 'mon', 'ne', 'nos', 'notre', 'nous', 'on', 'ont', 'ou', 'où', 'par', 'parce',
        'pas', 'pendant', 'peu', 'plus', 'pour', 'pourquoi', 'près', 'quand', 'que', 'quel',
        'quelle', 'quels', 'qui', 'quoi', 'sa', 'sans', 'se', 'selon', 'ses', 'si', 'soit',
        'son', 'sont', 'sous', 'sur', 'ta', 'tandis', 'tellement', 'tes', 'tous', 'tout',
        'toute', 'toutes', 'très', 'tu', 'un', 'une', 'vers', 'voici', 'voilà', 'vos', 'votre',
        'vous', 'y',
    ];

    public function __construct(
        private ArticleRepository $articles,
        private CategoryRepository $categories,
        private Randomizer $randomizer = new Randomizer(),
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
        $matches = $this->articles->searchUnreadOwnedWithRelevance(
            $userId,
            $categoryId,
            $uncategorized,
            $this->ftsQuery($terms),
            self::CANDIDATE_LIMIT,
        );
        if (count($matches) === 0) {
            return [];
        }

        return $this->rank($matches, $signals);
    }

    /**
     * Un titre de favori ne doit pas noyer les tags : les deux sources
     * alimentent la requête, chacune avec son propre plafond.
     *
     * @param list<array{id: int, title: string, tags: list<string>, category_id: int|null}> $signals
     * @return list<string>
     */
    private function terms(array $signals): array
    {
        $titleTerms = [];
        $tagTerms = [];
        foreach ($signals as $signal) {
            $title = is_string($signal['title'] ?? null) ? $signal['title'] : '';
            $titleCount = 0;
            $matched = preg_match_all('/[\p{L}\p{N}]+/u', $title, $matches);
            if (is_int($matched) && $matched > 0) {
                foreach ($matches[0] as $term) {
                    if (!$this->isUsefulTerm($term)) {
                        continue;
                    }
                    if (in_array($term, $titleTerms, true) || in_array($term, $tagTerms, true)) {
                        continue;
                    }
                    $titleTerms[] = $term;
                    $titleCount++;
                    if ($titleCount >= self::TITLE_TERM_LIMIT_PER_FAVORITE
                        || count($titleTerms) >= self::TITLE_TERM_LIMIT
                    ) {
                        break;
                    }
                }
            }
            foreach (is_array($signal['tags'] ?? null) ? $signal['tags'] : [] as $tag) {
                if ($this->isUsefulTerm($tag)
                    && !in_array($tag, $titleTerms, true)
                    && !in_array($tag, $tagTerms, true)
                    && count($tagTerms) < self::TAG_TERM_LIMIT
                ) {
                    $tagTerms[] = $tag;
                }
            }
        }

        return array_merge($titleTerms, $tagTerms);
    }

    private function isUsefulTerm(string $term): bool
    {
        $length = mb_strlen($term, 'UTF-8');
        if ($length < self::MIN_TERM_LENGTH || $length > self::MAX_TERM_LENGTH) {
            return false;
        }
        if (in_array(mb_strtolower($term, 'UTF-8'), self::STOP_WORDS, true)) {
            return false;
        }

        return !preg_match('/\A[\p{N}]+\z/u', $term);
    }

    /** @param list<string> $terms */
    private function ftsQuery(array $terms): string
    {
        return implode(' OR ', array_map(
            static fn(string $term): string => '"' . str_replace('"', '', $term) . '"*',
            $terms,
        ));
    }

    /**
     * score = 1 + 3 × pertinence FTS normalisée + 2 × tags partagés + 2 × même
     * catégorie qu'un favori, plus un bruit borné à ±0,6. L'égalité est
     * départagée par la date décroissante puis par l'identifiant.
     *
     * @param list<array{article: Article, relevance: float}> $matches
     * @param list<array{id: int, title: string, tags: list<string>, category_id: int|null}> $signals
     * @return list<Article>
     */
    private function rank(array $matches, array $signals): array
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

        $bestRelevance = 0.0;
        foreach ($matches as $match) {
            $bestRelevance = max($bestRelevance, $match['relevance']);
        }

        $scored = [];
        foreach ($matches as $match) {
            $article = $match['article'];
            if ($article->favorite) {
                continue;
            }
            $tags = is_array($article->tags) ? $article->tags : [];
            $tagScore = count($favoriteTags) > 0 && count($tags) > 0
                ? self::TAG_WEIGHT * count(array_intersect($tags, $favoriteTags))
                : 0;
            $categoryScore = $article->categoryId !== null
                && in_array($article->categoryId, $favoriteCategories, true) ? self::CATEGORY_WEIGHT : 0;
            $relevanceScore = $bestRelevance > 0.0
                ? self::RELEVANCE_WEIGHT * ($match['relevance'] / $bestRelevance)
                : 0.0;
            $scored[] = [
                'score' => 1 + $tagScore + $categoryScore + $relevanceScore
                    + ($this->randomizer->getFloat(0, 1) * 2 - 1) * self::JITTER,
                'article' => $article,
            ];
        }
        usort($scored, static function (array $left, array $right): int {
            $byScore = $right['score'] <=> $left['score'];
            if ($byScore !== 0) {
                return $byScore;
            }
            $leftDate = $left['article']->publishedAt ?? $left['article']->discoveredAt;
            $rightDate = $right['article']->publishedAt ?? $right['article']->discoveredAt;
            $byDate = $rightDate <=> $leftDate;

            return $byDate !== 0 ? $byDate : $left['article']->id <=> $right['article']->id;
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
