<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Clock\SystemClock;
use App\Exception\NotFoundException;
use App\Model\Article;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use DateTimeImmutable;
use Exception;
use Random\Randomizer;

/**
 * Recommendations are computed from the user's own reading history only:
 * keywords of recent favorite articles matched through FTS, plus tag and
 * category affinity. They never use other users' data and are scoring
 * only (no AI, no external service). A small bounded jitter breaks score
 * ties so the same articles do not stay pinned at the top of the view.
 */
final readonly class RecommendationService implements RecommendationProvider
{
    private const FAVORITE_SIGNAL_LIMIT = 20;
    private const CANDIDATE_LIMIT = 120;
    private const RECOMMENDATION_POOL_LIMIT = 96;
    private const RECOMMENDATION_LIMIT = 48;
    /**
     * Nombre maximal d'articles qu'un seul flux peut occuper dans le pool, donc
     * dans la vue. Sans cette limite, un flux proche des favoris et riche en
     * articles peut occuper la liste entière, alors que d'autres sources
     * comparables restent invisibles. Consequence assumée : la liste affiche
     * moins de 48 articles quand l'utilisateur compte moins de dix sources
     * possédant des articles éligibles.
     */
    private const MAX_PER_FEED = 5;
    private const TITLE_TERM_LIMIT_PER_FAVORITE = 6;
    private const TITLE_TERM_LIMIT = 30;
    private const TAG_TERM_LIMIT = 30;
    private const MIN_TERM_LENGTH = 3;
    private const MAX_TERM_LENGTH = 32;
    /**
     * Score minimal pour être recommandé. La base vaut 1, donc 2 exige une
     * vraie raison de matcher : au moins un tag partagé (+2), la même catégorie
     * qu'un favori (+2), ou une pertinence FTS d'au plus un tiers du meilleur
     * candidat. En deçà, l'article ne partage qu'un mot banal et n'est pas
     * affiché. Le bruit n'entre pas dans ce calcul, pour que l'inclusion reste
     * reproductible d'un chargement à l'autre.
     */
    private const MIN_SCORE = 2.0;
    private const RELEVANCE_WEIGHT = 3.0;
    private const FRESHNESS_WEIGHT = 1.0;
    private const FRESHNESS_WINDOW_SECONDS = 1_296_000;
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
        private Clock $clock = new SystemClock(),
    ) {}

    /** @return list<Article> */
    public function forUser(
        int $userId,
        ?int $categoryId = null,
        bool $uncategorized = false,
        ?int $limit = null,
    ): array {
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

        return $this->rank($matches, $signals, $limit ?? self::RECOMMENDATION_LIMIT);
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
     * catégorie qu'un favori, plus un bruit borné à ±0,6 appliqué au seul
     * classement. Seuls les candidats dont la partie déterministe atteint
     * MIN_SCORE sont retenus. L'égalité est départagée par la date décroissante
     * puis par l'identifiant.
     *
     * @param list<array{article: Article, relevance: float}> $matches
     * @param list<array{id: int, title: string, tags: list<string>, category_id: int|null}> $signals
     * @return list<Article>
     */
    private function rank(array $matches, array $signals, int $limit): array
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

        // Le favori qui a produit les termes matche lui aussi la requête FTS,
        // et son titre est par nature très proche de la requête. Le garder dans
        // la référence de normalisation écrasait la pertinence de tous les
        // autres candidats, qui se retrouvait à zéro. On ne normalise donc que
        // sur les candidats réellement recommandables.
        $candidates = [];
        foreach ($matches as $match) {
            if (!$match['article']->favorite) {
                $candidates[] = $match;
            }
        }

        $bestRelevance = 0.0;
        foreach ($candidates as $match) {
            $bestRelevance = max($bestRelevance, $match['relevance']);
        }

        $scored = [];
        foreach ($candidates as $match) {
            $article = $match['article'];
            $tags = is_array($article->tags) ? $article->tags : [];
            $tagScore = count($favoriteTags) > 0 && count($tags) > 0
                ? self::TAG_WEIGHT * count(array_intersect($tags, $favoriteTags))
                : 0;
            $categoryScore = $article->categoryId !== null
                && in_array($article->categoryId, $favoriteCategories, true) ? self::CATEGORY_WEIGHT : 0;
            $relevanceScore = $bestRelevance > 0.0
                ? self::RELEVANCE_WEIGHT * ($match['relevance'] / $bestRelevance)
                : 0.0;
            // Le seuil porte sur la partie déterministe, jamais sur le score
            // bruité : sinon le même article pourrait entrer ou non selon le
            // tirage aléatoire, et la sélection deviendrait non reproductible.
            $baseScore = 1 + $tagScore + $categoryScore + $relevanceScore;
            if ($baseScore < self::MIN_SCORE) {
                continue;
            }
            $scored[] = [
                'score' => $baseScore
                    + $this->freshnessScore($article)
                    + (($this->randomizer->getInt(0, 1_000_000) / 1_000_000) * 2 - 1) * self::JITTER,
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

        $pool = $this->limitPerFeed($scored);
        if (count($pool) <= $limit) {
            return $this->mostRecentFirst($pool);
        }

        $pool = $this->randomizer->shuffleArray($pool);

        return $this->mostRecentFirst(array_slice($pool, 0, $limit));
    }

    /**
     * La sélection du pool reste pilotée par le score, mais une fois les
     * articles retenus la vue les présente du plus récent au plus ancien. La
     * question que pose la page est « qu'est-ce que j'ai de neuf ? », et
     * l'ordre de score mélangeait des articles de jours différents sans que
     * l'ordre affiché soit lisible. Le classement par score reste déterminant
     * pour le choix des articles, pas pour leur présentation.
     *
     * @param list<array{score: float, article: Article}> $entries
     * @return list<Article>
     */
    private function mostRecentFirst(array $entries): array
    {
        usort($entries, static function (array $left, array $right): int {
            $leftDate = $left['article']->publishedAt ?? $left['article']->discoveredAt;
            $rightDate = $right['article']->publishedAt ?? $right['article']->discoveredAt;
            $byDate = $rightDate <=> $leftDate;

            return $byDate !== 0 ? $byDate : $left['article']->id <=> $right['article']->id;
        });

        return array_map(
            static fn(array $entry): Article => $entry['article'],
            $entries,
        );
    }

    /**
     * Aucun flux ne peut occuper plus de MAX_PER_FEED places. La limite s'applique
     * au pool et non aux seuls articles affichés : plafonner à la sortie
     * laisserait passer des groupes d'un seul flux dès qu'ils remplissent le pool.
     *
     * @param list<array{score: float, article: Article}> $scored
     * @return list<array{score: float, article: Article}>
     */
    private function limitPerFeed(array $scored): array
    {
        $counted = [];
        $pool = [];
        foreach ($scored as $entry) {
            $feedId = $entry['article']->feedId;
            $counted[$feedId] = ($counted[$feedId] ?? 0) + 1;
            if ($counted[$feedId] > self::MAX_PER_FEED) {
                continue;
            }
            $pool[] = $entry;
            if (count($pool) >= self::RECOMMENDATION_POOL_LIMIT) {
                break;
            }
        }

        return $pool;
    }

    private function freshnessScore(Article $article): float
    {
        $date = $article->publishedAt ?? $article->discoveredAt;
        try {
            $age = $this->clock->now()->getTimestamp() - (new DateTimeImmutable($date))->getTimestamp();
        } catch (Exception) {
            return 0.0;
        }
        $ratio = max(0.0, min(1.0, $age / self::FRESHNESS_WINDOW_SECONDS));

        return self::FRESHNESS_WEIGHT * (1.0 - $ratio);
    }
}
