<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\RemoteHttpException;
use App\Http\UrlResolver;
use DOMDocument;
use DOMElement;

final readonly class ArticleImageMetadataParser
{
    private const SUPPORTED_NAMES = [
        'og:image',
        'og:image:url',
        'og:image:secure_url',
        'twitter:image',
        'twitter:image:src',
    ];

    public function __construct(private UrlResolver $urls) {}

    /** @return list<string> */
    public function parse(string $html, string $documentUrl): array
    {
        if (trim($html) === '') {
            return [];
        }

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
            if (!$base instanceof DOMElement) {
                continue;
            }
            $resolved = $this->resolve($documentUrl, $base->getAttribute('href'));
            if ($resolved !== null) {
                $baseUrl = $resolved;
            }
            break;
        }

        $candidates = [];
        foreach ($document->getElementsByTagName('meta') as $meta) {
            if (!$meta instanceof DOMElement) {
                continue;
            }
            $name = strtolower(trim($meta->getAttribute('property') ?: $meta->getAttribute('name')));
            if (!in_array($name, self::SUPPORTED_NAMES, true)) {
                continue;
            }
            $candidate = $this->resolve($baseUrl, $meta->getAttribute('content'));
            if ($candidate !== null && !in_array($candidate, $candidates, true)) {
                $candidates[] = $candidate;
            }
        }

        return $candidates;
    }

    private function resolve(string $baseUrl, string $candidate): ?string
    {
        if (trim($candidate) === '') {
            return null;
        }
        try {
            $resolved = $this->urls->resolve($baseUrl, $candidate);
        } catch (RemoteHttpException) {
            return null;
        }

        $scheme = strtolower((string) parse_url($resolved, PHP_URL_SCHEME));

        return in_array($scheme, ['http', 'https'], true) ? $resolved : null;
    }
}
