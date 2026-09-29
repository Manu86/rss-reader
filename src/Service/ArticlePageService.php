<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RemoteHttpException;
use App\Http\SafeHttpClient;
use App\Model\ArticlePageData;

final readonly class ArticlePageService
{
    private const MAX_HTML_BYTES = 1_000_000;
    private const MIN_CONTENT_IMAGE_SIDE = 200;

    public function __construct(
        private SafeHttpClient $http,
        private ArticlePageContentParser $contentParser,
        private ArticleImageMetadataParser $imageParser,
        private string $userAgent,
    ) {}

    public function fetch(string $url): ArticlePageData
    {
        $response = $this->http->get(
            $url,
            ['accept' => 'text/html,application/xhtml+xml;q=0.9'],
            self::MAX_HTML_BYTES,
            $this->userAgent,
        );
        if ($response->status < 200 || $response->status >= 300 || !$this->isHtml($response->header('content-type'))) {
            throw new RemoteHttpException('INVALID_ARTICLE_PAGE', 'La page de l’article n’est pas disponible.');
        }
        $images = [];
        foreach ($this->imageParser->parse($response->body, $response->finalUrl) as $image) {
            $images[] = ['url' => $image, 'min_side' => 1];
        }
        foreach ($this->imageParser->parseContentImages($response->body, $response->finalUrl) as $image) {
            if (!in_array($image, array_column($images, 'url'), true)) {
                $images[] = ['url' => $image, 'min_side' => self::MIN_CONTENT_IMAGE_SIDE];
            }
        }

        return new ArticlePageData(
            $this->contentParser->parse($response->body, $response->finalUrl),
            $images,
        );
    }

    private function isHtml(?string $contentType): bool
    {
        if ($contentType === null || trim($contentType) === '') {
            return true;
        }
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        return in_array($mime, ['text/html', 'application/xhtml+xml'], true);
    }
}
