<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Model\Article;
use App\Security\CurrentUser;
use App\Service\RecommendationService;

final readonly class RecommendationController
{
    public function __construct(
        private RecommendationService $recommendations,
        private CurrentUser $currentUser,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser->require();
        $query = $request->query();
        // `page` est accepté et ignoré : la vue recommandations n'est pas paginée.
        $request->rejectUnknownFields($query, ['category_id', 'category', 'page']);
        $categoryId = $this->positiveInteger($query, 'category_id');
        $category = $this->string($query, 'category');
        if ($category !== null && $category !== 'uncategorized') {
            throw new ValidationException(['category' => 'La catégorie virtuelle demandée est invalide.']);
        }
        if ($categoryId !== null && $category !== null) {
            throw new ValidationException([
                'category' => 'category et category_id ne peuvent pas être utilisés ensemble.',
            ]);
        }

        return Response::json([
            'data' => array_map(
                static fn(Article $article): array => $article->listData(),
                $this->recommendations->forUser($user->id, $categoryId, $category === 'uncategorized'),
            ),
        ]);
    }

    /** @param array<string, mixed> $values */
    private function positiveInteger(array $values, string $key): ?int
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }
        $value = $values[$key];
        if (!is_string($value) || preg_match('/\A[1-9][0-9]{0,9}\z/', $value) !== 1) {
            throw new ValidationException([$key => 'Un entier positif est requis.']);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $values */
    private function string(array $values, string $key): ?string
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }
        if (!is_string($values[$key]) || $values[$key] === '') {
            throw new ValidationException([$key => 'Une valeur textuelle est requise.']);
        }

        return $values[$key];
    }
}
