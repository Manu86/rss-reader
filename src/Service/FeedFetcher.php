<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\FeedSyncException;
use App\Exception\RemoteHttpException;
use App\Http\SafeHttpClient;
use App\Model\FeedFetchResult;

final readonly class FeedFetcher
{
    /** @var list<string> */
    private const SUPPORTED_CONTENT_TYPES = [
        'application/rss+xml',
        'application/atom+xml',
        'application/xml',
        'text/xml',
        'text/plain',
    ];

    public function __construct(
        private SafeHttpClient $http,
        private FeedParser $parser,
    ) {}

    public function fetch(string $url, ?string $etag = null, ?string $lastModified = null): FeedFetchResult
    {
        $headers = ['accept' => 'application/rss+xml, application/atom+xml, application/xml, text/xml;q=0.9'];
        if ($etag !== null && $etag !== '') {
            $headers['if-none-match'] = $etag;
        }
        if ($lastModified !== null && $lastModified !== '') {
            $headers['if-modified-since'] = $lastModified;
        }

        try {
            $response = $this->http->get($url, $headers);
        } catch (RemoteHttpException) {
            throw FeedSyncException::remoteFailure();
        }
        if ($response->status === 304) {
            return new FeedFetchResult(true, null, $etag, $lastModified);
        }
        if ($response->status < 200 || $response->status >= 300) {
            throw FeedSyncException::remoteFailure();
        }

        $contentType = $response->header('content-type');
        if ($contentType !== null) {
            $mime = strtolower(trim(explode(';', $contentType, 2)[0]));
            if (!in_array($mime, self::SUPPORTED_CONTENT_TYPES, true)) {
                throw FeedSyncException::invalidFeed();
            }
        }

        $feed = $this->parser->parse($response->body, $response->finalUrl);
        if ($feed === null) {
            throw FeedSyncException::invalidFeed();
        }

        return new FeedFetchResult(
            false,
            $feed,
            $this->boundedHeader($response->header('etag')),
            $this->boundedHeader($response->header('last-modified')),
        );
    }

    private function boundedHeader(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);

        return $value !== '' && strlen($value) <= 1024 ? $value : null;
    }
}
