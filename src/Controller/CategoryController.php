<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Model\Category;
use App\Security\CurrentUser;
use App\Service\CategoryService;

final readonly class CategoryController
{
    public function __construct(
        private CategoryService $categories,
        private CurrentUser $currentUser,
    ) {}

    public function index(): Response
    {
        $user = $this->currentUser->require();

        return Response::json(['data' => array_map(
            static fn(Category $category): array => $category->publicData(),
            $this->categories->list($user->id),
        )]);
    }

    public function create(Request $request): Response
    {
        $user = $this->currentUser->require();

        return Response::json(['data' => $this->categories->create(
            $user->id,
            $this->name($request),
        )->publicData()], 201);
    }

    public function update(Request $request, int $categoryId): Response
    {
        $user = $this->currentUser->require();

        return Response::json(['data' => $this->categories->update(
            $categoryId,
            $user->id,
            $this->name($request),
        )->publicData()]);
    }

    public function delete(int $categoryId): Response
    {
        $user = $this->currentUser->require();
        $this->categories->delete($categoryId, $user->id);

        return Response::empty();
    }

    private function name(Request $request): string
    {
        $data = $request->json();
        $request->rejectUnknownFields($data, ['name']);
        if (!isset($data['name']) || !is_string($data['name'])) {
            throw new ValidationException(['name' => 'Le nom est requis.']);
        }

        return $data['name'];
    }
}
