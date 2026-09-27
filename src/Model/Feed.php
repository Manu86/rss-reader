<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Feed
{
    public function __construct(
        public int $id,
        public int $userId,
        public ?int $categoryId,
        public string $name,
        public string $feedUrl,
        public ?string $siteUrl,
        public ?string $faviconPath,
        public bool $active,
        public ?string $lastFetchAttemptAt,
        public ?string $lastSuccessfulFetchAt,
        public ?string $lastArticleAt,
        public ?string $lastFetchStatus,
        public ?string $lastFetchError,
        public ?string $etag,
        public ?string $lastModified,
        public string $createdAt,
        public string $updatedAt,
    ) {}

    /** @return array<string, int|string|bool|null> */
    public function publicData(): array
    {
        return [
            'id' => $this->id,
            'category_id' => $this->categoryId,
            'name' => html_entity_decode(
                html_entity_decode($this->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8',
            ),
            'feed_url' => $this->feedUrl,
            'site_url' => $this->siteUrl,
            'favicon_url' => $this->faviconPath === null ? null : '/api/feeds/' . $this->id . '/favicon',
            'is_active' => $this->active,
            'last_fetch_attempt_at' => $this->lastFetchAttemptAt,
            'last_successful_fetch_at' => $this->lastSuccessfulFetchAt,
            'last_article_at' => $this->lastArticleAt,
            'last_fetch_status' => $this->lastFetchStatus,
            'last_fetch_error' => $this->lastFetchError,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
        ];
    }
}
