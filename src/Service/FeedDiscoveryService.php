<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use App\Exception\RemoteHttpException;
use App\Exception\ValidationException;
use App\Http\SafeHttpClient;
use App\Model\FeedCandidate;
use App\Security\RemoteActionRateLimiter;

final readonly class FeedDiscoveryService
{
    private const ACTION = 'feed_discovery';

    /** @var list<string> */
    private const XML_TYPES = [
        'application/rss+xml',
        'application/atom+xml',
        'application/xml',
        'text/xml',
        'text/plain',
    ];

    /** @var list<string> */
    private const HTML_TYPES = ['text/html', 'application/xhtml+xml'];

    public function __construct(
        private SafeHttpClient $http,
        private FeedDocumentDetector $feedDocuments,
        private HtmlFeedDiscoveryParser $html,
        private RemoteActionRateLimiter $rateLimiter,
    ) {}

    /** @return array{site_url: string, feeds: list<array{title: string, url: string, type: string}>} */
    public function discover(int $userId, string $url): array
    {
        $this->rateLimiter->consume($userId, self::ACTION);

        try {
            $response = $this->http->get($url, [
                'accept' => 'text/html, application/xhtml+xml, application/rss+xml, '
                    . 'application/atom+xml, application/xml;q=0.9, text/xml;q=0.9',
            ]);
        } catch (RemoteHttpException $exception) {
            if (in_array($exception->reason, ['UNSAFE_URL', 'UNSAFE_ADDRESS', 'DNS_FAILED'], true)) {
                throw new ValidationException(['url' => 'L’URL est invalide, inaccessible ou interdite.']);
            }
            throw new ApiException(502, 'REMOTE_FETCH_FAILED', 'La ressource distante n’a pas pu être récupérée.');
        }

        if ($response->status < 200 || $response->status >= 300) {
            throw new ApiException(502, 'REMOTE_FETCH_FAILED', 'La ressource distante a répondu avec une erreur.');
        }

        $contentType = $this->contentType($response->header('content-type'));
        if ($contentType === null || in_array($contentType, self::XML_TYPES, true)) {
            $metadata = $this->feedDocuments->detect($response->body);
            if ($metadata !== null) {
                $candidate = new FeedCandidate(
                    $this->titleOrHost($metadata['title'], $response->finalUrl),
                    $response->finalUrl,
                    $metadata['type'],
                );

                return ['site_url' => $response->finalUrl, 'feeds' => [$candidate->publicData()]];
            }
        }

        if (!$this->isHtml($contentType, $response->body)) {
            throw new ApiException(
                422,
                'UNSUPPORTED_REMOTE_CONTENT',
                'La ressource distante n’est ni une page HTML ni un flux RSS/Atom valide.',
            );
        }

        $candidates = [];
        foreach ($this->html->parse($response->body, $response->finalUrl) as $candidate) {
            try {
                $normalizedUrl = $this->http->validateUrl($candidate->url);
            } catch (RemoteHttpException) {
                continue;
            }
            if (isset($candidates[$normalizedUrl])) {
                continue;
            }
            $candidates[$normalizedUrl] = new FeedCandidate(
                $this->titleOrHost($candidate->title, $normalizedUrl),
                $normalizedUrl,
                $candidate->type,
            );
        }

        if ($candidates === []) {
            throw new ApiException(422, 'NO_FEED_FOUND', 'Aucun flux RSS ou Atom n’a été trouvé.');
        }

        return [
            'site_url' => $response->finalUrl,
            'feeds' => array_map(
                static fn(FeedCandidate $candidate): array => $candidate->publicData(),
                array_values($candidates),
            ),
        ];
    }

    private function contentType(?string $header): ?string
    {
        if ($header === null || trim($header) === '') {
            return null;
        }

        return strtolower(trim(explode(';', $header, 2)[0]));
    }

    private function isHtml(?string $contentType, string $body): bool
    {
        if ($contentType !== null) {
            return in_array($contentType, self::HTML_TYPES, true);
        }

        $start = strtolower(ltrim(substr($body, 0, 512)));

        return str_starts_with($start, '<!doctype html') || str_starts_with($start, '<html');
    }

    private function titleOrHost(string $title, string $url): string
    {
        $title = trim($title);
        if ($title !== '') {
            return $title;
        }
        $host = parse_url($url, PHP_URL_HOST);

        return is_string($host) && $host !== '' ? $host : 'Flux sans titre';
    }
}
