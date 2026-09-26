<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RemoteHttpException;
use App\Http\UrlResolver;
use App\Model\FeedCandidate;
use DOMDocument;
use DOMElement;

final readonly class HtmlFeedDiscoveryParser
{
    public function __construct(private UrlResolver $urls) {}

    /** @return list<FeedCandidate> */
    public function parse(string $html, string $documentUrl): array
    {
        $document = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        try {
            $loaded = $document->loadHTML(
                $html,
                LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING | LIBXML_COMPACT,
            );
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
        if (!$loaded) {
            return [];
        }

        $baseUrl = $documentUrl;
        foreach ($document->getElementsByTagName('base') as $base) {
            $href = trim($base->getAttribute('href'));
            if ($href !== '') {
                try {
                    $baseUrl = $this->urls->resolve($documentUrl, $href);
                } catch (RemoteHttpException) {
                    $baseUrl = $documentUrl;
                }
                break;
            }
        }

        $titles = $document->getElementsByTagName('title');
        $titleElement = $titles->item(0);
        $pageTitle = $titleElement instanceof DOMElement ? trim($titleElement->textContent) : '';
        $feeds = [];
        foreach ($document->getElementsByTagName('link') as $link) {
            if (!$link instanceof DOMElement || !$this->isAlternate($link->getAttribute('rel'))) {
                continue;
            }
            $type = $this->feedType($link->getAttribute('type'));
            $href = trim($link->getAttribute('href'));
            if ($type === null || $href === '') {
                continue;
            }
            try {
                $url = $this->urls->resolve($baseUrl, $href);
            } catch (RemoteHttpException) {
                continue;
            }
            $title = trim($link->getAttribute('title'));
            $feeds[] = new FeedCandidate($title !== '' ? $title : $pageTitle, $url, $type);
        }

        return $feeds;
    }

    private function isAlternate(string $rel): bool
    {
        return in_array('alternate', preg_split('/\s+/', strtolower(trim($rel))) ?: [], true);
    }

    /** @return 'rss'|'atom'|null */
    private function feedType(string $contentType): ?string
    {
        $mime = strtolower(trim(explode(';', $contentType, 2)[0]));

        return match ($mime) {
            'application/rss+xml' => 'rss',
            'application/atom+xml' => 'atom',
            default => null,
        };
    }
}
