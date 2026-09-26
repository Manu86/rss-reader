<?php

declare(strict_types=1);

namespace App\Service;

use App\Clock\Clock;
use App\Exception\ApiException;
use App\Exception\ConflictException;
use App\Exception\NotFoundException;
use App\Exception\ValidationException;
use App\Model\Feed;
use App\Repository\CategoryRepository;
use App\Repository\FeedRepository;
use App\Security\RemoteActionRateLimiter;
use App\Validation\UrlNormalizer;
use Throwable;

final readonly class FeedService
{
    public function __construct(
        private FeedRepository $feeds,
        private CategoryRepository $categories,
        private UrlNormalizer $urls,
        private Clock $clock,
        private FeedSynchronizationService $synchronization,
        private RemoteActionRateLimiter $rateLimiter,
        private MediaCleanupService $mediaCleanup,
    ) {}

    /** @return list<Feed> */
    public function list(int $userId, ?int $categoryId, ?bool $active): array
    {
        if ($categoryId !== null) {
            $this->assertOwnedCategory($categoryId, $userId);
        }

        return $this->feeds->listOwned($userId, $categoryId, $active);
    }

    public function get(int $feedId, int $userId): Feed
    {
        $feed = $this->feeds->findOwned($feedId, $userId);
        if ($feed === null) {
            throw new NotFoundException('Abonnement introuvable.');
        }

        return $feed;
    }

    public function create(int $userId, ?string $name, string $feedUrl, ?int $categoryId): Feed
    {
        $name = $name === null ? null : $this->validateName($name);
        $feedUrl = $this->urls->normalizeHttpUrl($feedUrl);
        if ($categoryId !== null) {
            $this->assertOwnedCategory($categoryId, $userId);
        }
        if ($this->feeds->existsOwnedUrl($userId, $feedUrl)) {
            throw new ConflictException('FEED_ALREADY_EXISTS', 'Cet abonnement existe déjà.');
        }
        $this->rateLimiter->consume($userId, 'feed_create');

        return $this->synchronization->create($userId, $categoryId, $name, $feedUrl);
    }

    /** @param array{name?: string, category_id?: int|null, is_active?: bool} $changes */
    public function update(int $feedId, int $userId, array $changes): Feed
    {
        if ($changes === []) {
            throw new ValidationException([], 'Au moins une modification est requise.');
        }
        $feed = $this->get($feedId, $userId);
        $name = array_key_exists('name', $changes)
            ? $this->validateName($changes['name'])
            : $feed->name;
        $categoryId = array_key_exists('category_id', $changes)
            ? $changes['category_id']
            : $feed->categoryId;
        $active = $changes['is_active'] ?? $feed->active;

        if ($categoryId !== null) {
            $this->assertOwnedCategory($categoryId, $userId);
        }

        return $this->feeds->update($feed, $name, $categoryId, $active, $this->now());
    }

    public function delete(int $feedId, int $userId): void
    {
        $this->get($feedId, $userId);
        $mediaPaths = $this->mediaCleanup->pathsForFeed($feedId, $userId);
        if (!$this->feeds->deleteOwned($feedId, $userId)) {
            throw new NotFoundException('Abonnement introuvable.');
        }
        $this->mediaCleanup->removeUnreferenced($userId, $mediaPaths);
    }

    /** @return array{feed: Feed, imported_articles: int, not_modified: bool} */
    public function refresh(int $feedId, int $userId): array
    {
        $feed = $this->get($feedId, $userId);
        if (!$feed->active) {
            throw new ConflictException('FEED_DISABLED', 'Un abonnement désactivé ne peut pas être actualisé.');
        }
        $this->rateLimiter->consume($userId, 'feed_refresh');

        return $this->synchronization->refresh($feed);
    }

    /** @return list<array{feed_id: int, status: string, imported_articles?: int, not_modified?: bool, error?: array{code: string, message: string}}> */
    public function refreshAll(int $userId): array
    {
        $this->rateLimiter->consume($userId, 'feed_refresh');
        $results = [];
        foreach ($this->feeds->listOwned($userId, null, true) as $feed) {
            try {
                $result = $this->synchronization->refresh($feed);
                $results[] = [
                    'feed_id' => $feed->id,
                    'status' => 'success',
                    'imported_articles' => $result['imported_articles'],
                    'not_modified' => $result['not_modified'],
                ];
            } catch (ApiException $exception) {
                $results[] = [
                    'feed_id' => $feed->id,
                    'status' => 'error',
                    'error' => [
                        'code' => $exception->errorCode,
                        'message' => $exception->getMessage(),
                    ],
                ];
            } catch (Throwable) {
                error_log(sprintf('Unexpected feed synchronization failure [feed:%d]', $feed->id));
                $results[] = [
                    'feed_id' => $feed->id,
                    'status' => 'error',
                    'error' => [
                        'code' => 'INTERNAL_ERROR',
                        'message' => 'Une erreur interne a interrompu la synchronisation.',
                    ],
                ];
            }
        }

        return $results;
    }

    private function validateName(string $name): string
    {
        $name = trim($name);
        $length = mb_strlen($name, 'UTF-8');
        if ($length < 1 || $length > 200) {
            throw new ValidationException([
                'name' => 'Le nom doit contenir entre 1 et 200 caractères.',
            ]);
        }

        return $name;
    }

    private function assertOwnedCategory(int $categoryId, int $userId): void
    {
        if ($categoryId < 1 || !$this->categories->existsOwned($categoryId, $userId)) {
            throw new NotFoundException('Catégorie introuvable.');
        }
    }

    private function now(): string
    {
        return $this->clock->now()->format('Y-m-d\TH:i:s\Z');
    }
}
