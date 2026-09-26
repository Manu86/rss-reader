<?php

declare(strict_types=1);

namespace App\Model;

final readonly class Article
{
    public function __construct(
        public int $id,
        public int $feedId,
        public string $feedName,
        public bool $feedHasFavicon,
        public ?int $categoryId,
        public ?string $categoryName,
        public string $title,
        public ?string $url,
        public ?string $author,
        public ?string $publishedAt,
        public string $discoveredAt,
        public ?string $summary,
        public ?string $content,
        public bool $hasImage,
        public bool $read,
        public bool $favorite,
    ) {}

    /** @return array<string, mixed> */
    public function listData(): array
    {
        return [
            'id' => $this->id,
            'feed' => [
                'id' => $this->feedId,
                'name' => $this->feedName,
                'favicon_url' => $this->feedHasFavicon
                    ? '/api/feeds/' . $this->feedId . '/favicon'
                    : null,
                'category' => $this->categoryId === null || $this->categoryName === null
                    ? null
                    : [
                        'id' => $this->categoryId,
                        'name' => $this->categoryName,
                    ],
            ],
            'title' => $this->title,
            'url' => $this->url,
            'author' => $this->author,
            'published_at' => $this->publishedAt,
            'discovered_at' => $this->discoveredAt,
            'summary' => $this->summary,
            'image_url' => $this->hasImage ? '/api/articles/' . $this->id . '/image' : null,
            'is_read' => $this->read,
            'is_favorite' => $this->favorite,
        ];
    }

    /** @return array<string, mixed> */
    public function detailData(): array
    {
        return $this->listData() + ['content' => $this->content];
    }
}
