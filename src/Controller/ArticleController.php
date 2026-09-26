<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Model\Article;
use App\Security\CurrentUser;
use App\Service\ArticleService;

final readonly class ArticleController
{
    public function __construct(
        private ArticleService $articles,
        private CurrentUser $currentUser,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser->require();
        $parameters = $this->listParameters($request);

        $result = $this->articles->list(
            $user->id,
            $parameters['filter'],
            $parameters['category_id'],
            $parameters['uncategorized'],
            $parameters['feed_id'],
            $parameters['page'],
            $parameters['per_page'],
        );

        return $this->listResponse($result);
    }

    public function search(Request $request): Response
    {
        $user = $this->currentUser->require();
        $parameters = $this->listParameters($request, ['q']);
        $query = $this->string($request->query(), 'q');
        if ($query === null) {
            throw new ValidationException(['q' => 'Le texte de recherche est requis.']);
        }
        $result = $this->articles->search(
            $user->id,
            $query,
            $parameters['filter'],
            $parameters['category_id'],
            $parameters['uncategorized'],
            $parameters['feed_id'],
            $parameters['page'],
            $parameters['per_page'],
        );

        return $this->listResponse($result);
    }

    public function counts(): Response
    {
        $user = $this->currentUser->require();

        return Response::json(['data' => $this->articles->counts($user->id)]);
    }

    public function show(int $articleId): Response
    {
        $user = $this->currentUser->require();

        return Response::json([
            'data' => $this->articles->detail($articleId, $user->id)->detailData(),
        ]);
    }

    public function update(Request $request, int $articleId): Response
    {
        $user = $this->currentUser->require();
        $data = $request->json();
        $request->rejectUnknownFields($data, ['is_read', 'is_favorite']);
        $read = $this->optionalBoolean($data, 'is_read');
        $favorite = $this->optionalBoolean($data, 'is_favorite');

        return Response::json([
            'data' => $this->articles->update($articleId, $user->id, $read, $favorite)->detailData(),
        ]);
    }

    /** @param list<string> $extraAllowed
     *  @return array{filter: string, category_id: int|null, uncategorized: bool, feed_id: int|null, page: int, per_page: int|null}
     */
    private function listParameters(Request $request, array $extraAllowed = []): array
    {
        $query = $request->query();
        $request->rejectUnknownFields($query, array_merge(
            ['filter', 'category_id', 'category', 'feed_id', 'page', 'per_page'],
            $extraAllowed,
        ));
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

        return [
            'filter' => $this->string($query, 'filter') ?? 'all',
            'category_id' => $categoryId,
            'uncategorized' => $category === 'uncategorized',
            'feed_id' => $this->positiveInteger($query, 'feed_id'),
            'page' => $this->positiveInteger($query, 'page') ?? 1,
            'per_page' => $this->positiveInteger($query, 'per_page'),
        ];
    }

    /** @param array{articles: list<Article>, criteria: \App\Model\ArticleListCriteria, total_items: int, total_pages: int} $result */
    private function listResponse(array $result): Response
    {
        return Response::json([
            'data' => array_map(
                static fn(Article $article): array => $article->listData(),
                $result['articles'],
            ),
            'pagination' => [
                'page' => $result['criteria']->page,
                'per_page' => $result['criteria']->perPage,
                'total_items' => $result['total_items'],
                'total_pages' => $result['total_pages'],
            ],
        ]);
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
    private function optionalBoolean(array $values, string $key): ?bool
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }
        if (!is_bool($values[$key])) {
            throw new ValidationException([$key => 'Un booléen est requis.']);
        }

        return $values[$key];
    }
}
