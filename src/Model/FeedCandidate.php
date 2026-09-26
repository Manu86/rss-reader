<?php

declare(strict_types=1);

namespace App\Model;

final readonly class FeedCandidate
{
    public function __construct(
        public string $title,
        public string $url,
        public string $type,
    ) {}

    /** @return array{title: string, url: string, type: string} */
    public function publicData(): array
    {
        return [
            'title' => $this->title,
            'url' => $this->url,
            'type' => $this->type,
        ];
    }
}
