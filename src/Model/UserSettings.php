<?php

declare(strict_types=1);

namespace App\Model;

final readonly class UserSettings
{
    /** @var list<int> */
    public const ALLOWED_PAGE_SIZES = [10, 25, 50, 100];

    /** @var list<string> */
    public const ALLOWED_THEMES = ['light', 'dark'];

    public const DEFAULT_PAGE_SIZE = 25;

    public const DEFAULT_THEME = 'light';

    public function __construct(
        public int $userId,
        public int $articlesPerPage,
        public string $theme = self::DEFAULT_THEME,
    ) {}

    /** @return array{articles_per_page: int, theme: string} */
    public function publicData(): array
    {
        return [
            'articles_per_page' => $this->articlesPerPage,
            'theme' => $this->theme,
        ];
    }
}
