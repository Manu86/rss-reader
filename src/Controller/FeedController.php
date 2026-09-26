<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Model\Feed;
use App\Security\CurrentUser;
use App\Service\FeedService;

final readonly class FeedController
{
    public function __construct(
        private FeedService $feeds,
        private CurrentUser $currentUser,
    ) {}

    public function index(Request $request): Response
    {
        $user = $this->currentUser->require();
        $query = $request->query();
        $request->rejectUnknownFields($query, ['category_id', 'active']);
        $categoryId = $this->optionalPositiveInteger($query, 'category_id');
        $active = $this->optionalBoolean($query, 'active');

        return Response::json(['data' => array_map(
            static fn(Feed $feed): array => $feed->publicData(),
            $this->feeds->list($user->id, $categoryId, $active),
        )]);
    }

    public function show(int $feedId): Response
    {
        $user = $this->currentUser->require();

        return Response::json(['data' => $this->feeds->get($feedId, $user->id)->publicData()]);
    }

    public function create(Request $request): Response
    {
        $user = $this->currentUser->require();
        $data = $request->json();
        $request->rejectUnknownFields($data, ['name', 'feed_url', 'category_id']);
        $name = $data['name'] ?? null;
        $feedUrl = $data['feed_url'] ?? null;
        $categoryId = $data['category_id'] ?? null;
        $fields = [];
        if ($name !== null && !is_string($name)) {
            $fields['name'] = 'Le nom doit être une chaîne ou être omis.';
        }
        if (!is_string($feedUrl)) {
            $fields['feed_url'] = 'L’URL du flux est requise.';
        }
        if ($categoryId !== null && (!is_int($categoryId) || $categoryId < 1)) {
            $fields['category_id'] = 'La catégorie doit être un identifiant positif ou null.';
        }
        if ($fields !== []) {
            throw new ValidationException($fields);
        }

        return Response::json(['data' => $this->feeds->create(
            $user->id,
            $name,
            $feedUrl,
            $categoryId,
        )->publicData()], 201);
    }

    public function update(Request $request, int $feedId): Response
    {
        $user = $this->currentUser->require();
        $data = $request->json();
        $request->rejectUnknownFields($data, ['name', 'category_id', 'is_active']);
        $changes = [];
        $fields = [];
        if (array_key_exists('name', $data)) {
            if (is_string($data['name'])) {
                $changes['name'] = $data['name'];
            } else {
                $fields['name'] = 'Le nom doit être une chaîne.';
            }
        }
        if (array_key_exists('category_id', $data)) {
            if ($data['category_id'] === null || (is_int($data['category_id']) && $data['category_id'] > 0)) {
                $changes['category_id'] = $data['category_id'];
            } else {
                $fields['category_id'] = 'La catégorie doit être un identifiant positif ou null.';
            }
        }
        if (array_key_exists('is_active', $data)) {
            if (is_bool($data['is_active'])) {
                $changes['is_active'] = $data['is_active'];
            } else {
                $fields['is_active'] = 'L’état actif doit être un booléen.';
            }
        }
        if ($fields !== []) {
            throw new ValidationException($fields);
        }

        return Response::json(['data' => $this->feeds->update(
            $feedId,
            $user->id,
            $changes,
        )->publicData()]);
    }

    public function delete(int $feedId): Response
    {
        $user = $this->currentUser->require();
        $this->feeds->delete($feedId, $user->id);

        return Response::empty();
    }

    public function refresh(int $feedId): Response
    {
        $user = $this->currentUser->require();
        $result = $this->feeds->refresh($feedId, $user->id);

        return Response::json(['data' => [
            'feed' => $result['feed']->publicData(),
            'imported_articles' => $result['imported_articles'],
            'not_modified' => $result['not_modified'],
        ]]);
    }

    public function refreshAll(): Response
    {
        $user = $this->currentUser->require();

        return Response::json(['data' => ['results' => $this->feeds->refreshAll($user->id)]]);
    }

    /** @param array<string, mixed> $values */
    private function optionalPositiveInteger(array $values, string $key): ?int
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }
        $value = $values[$key];
        if (!is_string($value) || preg_match('/\A[1-9][0-9]*\z/', $value) !== 1) {
            throw new ValidationException([$key => 'Un identifiant positif est requis.']);
        }

        return (int) $value;
    }

    /** @param array<string, mixed> $values */
    private function optionalBoolean(array $values, string $key): ?bool
    {
        if (!array_key_exists($key, $values)) {
            return null;
        }

        return match ($values[$key]) {
            '1', 'true' => true,
            '0', 'false' => false,
            default => throw new ValidationException([$key => 'Un booléen est requis.']),
        };
    }
}
