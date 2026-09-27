<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Article;
use App\Model\ArticleListCriteria;
use App\Repository\ArticleCountRepository;
use App\Repository\ArticleRepository;
use App\Repository\CategoryRepository;
use App\Repository\FeedRepository;
use App\Repository\UserSettingsRepository;

final readonly class ArticleService
{
    /** @var list<int> */
    public const ALLOWED_PAGE_SIZES = [10, 25, 50, 100];
    public const DEFAULT_PAGE_SIZE = 25;

    /** @var list<string> */
    public const ALLOWED_FILTERS = ['all', 'unread', 'read', 'favorites'];

    public function __construct(
        private ArticleRepository $articles,
        private FeedRepository $feeds,
        private CategoryRepository $categories,
        private UserSettingsRepository $settings,
        private ArticleCountRepository $counts,
        private FtsQueryBuilder $ftsQueryBuilder,
        private Clock $clock,
    ) {}

    /** @return array{articles: list<Article>, criteria: ArticleListCriteria, total_items: int, total_pages: int} */
    public function list(
        int $userId,
        string $filter,
        ?int $categoryId,
        bool $uncategorized,
        ?int $feedId,
        int $page,
        ?int $perPage,
    ): array {
        $criteria = $this->criteria(
            $userId,
            $filter,
            $categoryId,
            $uncategorized,
            $feedId,
            $page,
            $perPage,
        );
        $totalItems = $this->articles->countOwned($userId, $criteria);

        return [
            'articles' => $this->articles->listOwned($userId, $criteria),
            'criteria' => $criteria,
            'total_items' => $totalItems,
            'total_pages' => $totalItems === 0 ? 0 : (int) ceil($totalItems / $criteria->perPage),
        ];
    }

    /** @return array{articles: list<Article>, criteria: ArticleListCriteria, total_items: int, total_pages: int} */
    public function search(
        int $userId,
        string $query,
        string $filter,
        ?int $categoryId,
        bool $uncategorized,
        ?int $feedId,
        int $page,
        ?int $perPage,
    ): array {
        $criteria = $this->criteria(
            $userId,
            $filter,
            $categoryId,
            $uncategorized,
            $feedId,
            $page,
            $perPage,
        );
        $ftsQuery = $this->ftsQueryBuilder->build($query);
        $totalItems = $this->articles->countSearchOwned($userId, $criteria, $ftsQuery);

        return [
            'articles' => $this->articles->searchOwned($userId, $criteria, $ftsQuery),
            'criteria' => $criteria,
            'total_items' => $totalItems,
            'total_pages' => $totalItems === 0 ? 0 : (int) ceil($totalItems / $criteria->perPage),
        ];
    }

    /** @return array{all: int, unread: int, read: int, favorites: int, categories: list<array{category_id: int, unread: int}>, uncategorized: int, feeds: list<array{feed_id: int, unread: int}>} */
    public function counts(int $userId): array
    {
        return $this->counts->forUser($userId);
    }

    public function detail(int $articleId, int $userId): Article
    {
        $article = $this->articles->findOwned($articleId, $userId);
        if ($article === null) {
            throw new NotFoundException('Article introuvable.');
        }

        return $article;
    }

    public function update(
        int $articleId,
        int $userId,
        ?bool $read,
        ?bool $favorite,
    ): Article {
        if ($read === null && $favorite === null) {
            throw new ValidationException([], 'Au moins une modification est requise.');
        }
        $article = $this->articles->updateStateOwned(
            $articleId,
            $userId,
            $read,
            $favorite,
            $this->now(),
        );
        if ($article === null) {
            throw new NotFoundException('Article introuvable.');
        }

        return $article;
    }

    private function criteria(
        int $userId,
        string $filter,
        ?int $categoryId,
        bool $uncategorized,
        ?int $feedId,
        int $page,
        ?int $perPage,
    ): ArticleListCriteria {
        if (!in_array($filter, self::ALLOWED_FILTERS, true)) {
            throw new ValidationException(['filter' => 'Le filtre demandé est invalide.']);
        }
        if ($page < 1 || $page > 1_000_000) {
            throw new ValidationException(['page' => 'Le numéro de page est invalide.']);
        }
        if ($categoryId !== null && !$this->categories->existsOwned($categoryId, $userId)) {
            throw new NotFoundException('Catégorie introuvable.');
        }
        if ($feedId !== null && $this->feeds->findOwned($feedId, $userId) === null) {
            throw new NotFoundException('Abonnement introuvable.');
        }
        $perPage ??= self::DEFAULT_PAGE_SIZE;
        if (!in_array($perPage, self::ALLOWED_PAGE_SIZES, true)) {
            throw new ValidationException(['per_page' => 'La taille de page demandée est invalide.']);
        }

        return new ArticleListCriteria(
            $filter,
            $categoryId,
            $uncategorized,
            $feedId,
            $page,
            $perPage,
        );
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
