<?php

declare(strict_types=1);

namespace App\Service;

use League\CommonMark\GithubFlavoredMarkdownConverter;

final readonly class MarkdownContentConverter
{
    private GithubFlavoredMarkdownConverter $converter;

    public function __construct()
    {
        $this->converter = new GithubFlavoredMarkdownConverter([
            'allow_unsafe_links' => false,
            'html_input' => 'escape',
            'max_delimiters_per_line' => 1000,
            'max_nesting_level' => 100,
        ]);
    }

    public function convert(string $markdown): string
    {
        return $this->converter->convert($markdown)->getContent();
    }
}
