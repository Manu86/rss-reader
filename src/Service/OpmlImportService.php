<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use App\Exception\ConflictException;
use App\Repository\FeedRepository;
use App\Security\RemoteActionRateLimiter;
use App\Validation\UrlNormalizer;
use Throwable;

final readonly class OpmlImportService
{
    private const ACTION = 'opml_import';

    public function __construct(
        private OpmlParser $parser,
        private CategoryService $categories,
        private FeedRepository $feeds,
        private FeedSynchronizationService $synchronization,
        private UrlNormalizer $urls,
        private RemoteActionRateLimiter $rateLimiter,
    ) {}

    /** @return array{imported: int, duplicates: int, failed: int, categories_created: int} */
    public function import(int $userId, string $xml): array
    {
        $document = $this->parser->parse($xml);
        $this->rateLimiter->consume($userId, self::ACTION);
        $categoryIds = [];
        foreach ($this->categories->list($userId) as $category) {
            $categoryIds[$this->key($category->name)] = $category->id;
        }
        $invalidCategories = [];
        $categoriesCreated = 0;
        foreach ($document['categories'] as $name) {
            $key = $this->key($name);
            if (isset($categoryIds[$key])) {
                continue;
            }
            try {
                $category = $this->categories->create($userId, $name);
                $categoryIds[$key] = $category->id;
                ++$categoriesCreated;
            } catch (ApiException) {
                $invalidCategories[$key] = true;
            }
        }

        $imported = 0;
        $duplicates = 0;
        $failed = 0;
        foreach ($document['subscriptions'] as $subscription) {
            $categoryId = null;
            if ($subscription['category_name'] !== null) {
                $categoryKey = $this->key($subscription['category_name']);
                if (isset($invalidCategories[$categoryKey]) || !isset($categoryIds[$categoryKey])) {
                    ++$failed;
                    continue;
                }
                $categoryId = $categoryIds[$categoryKey];
            }
            $name = $subscription['name'] === null ? null : trim($subscription['name']);
            if ($name !== null && (mb_strlen($name, 'UTF-8') < 1 || mb_strlen($name, 'UTF-8') > 200)) {
                ++$failed;
                continue;
            }

            try {
                $feedUrl = $this->urls->normalizeHttpUrl($subscription['feed_url']);
                if ($this->feeds->existsOwnedUrl($userId, $feedUrl)) {
                    ++$duplicates;
                    continue;
                }
                $this->synchronization->create($userId, $categoryId, $name, $feedUrl);
                ++$imported;
            } catch (ConflictException $exception) {
                if ($exception->errorCode === 'FEED_ALREADY_EXISTS') {
                    ++$duplicates;
                } else {
                    ++$failed;
                }
            } catch (ApiException) {
                ++$failed;
            } catch (Throwable) {
                ++$failed;
                error_log(sprintf('Unexpected OPML subscription import failure [user:%d]', $userId));
            }
        }

        return [
            'imported' => $imported,
            'duplicates' => $duplicates,
            'failed' => $failed,
            'categories_created' => $categoriesCreated,
        ];
    }

    private function key(string $name): string
    {
        return mb_strtolower(trim($name), 'UTF-8');
    }
}
