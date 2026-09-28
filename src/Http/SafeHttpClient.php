<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\RemoteHttpException;
use App\Security\RemoteUrlGuard;

final readonly class SafeHttpClient
{
    /** @var list<string> */
    private const ALLOWED_REQUEST_HEADERS = [
        'accept',
        'accept-language',
        'if-none-match',
        'if-modified-since',
    ];

    public function __construct(
        private RemoteUrlGuard $guard,
        private HttpTransport $transport,
        private UrlResolver $urlResolver,
        private int $connectTimeoutMs,
        private int $timeoutMs,
        private int $maxResponseBytes,
        private int $maxRedirects,
        private string $userAgent,
    ) {}

    /** @param array<string, string> $headers */
    public function get(
        string $url,
        array $headers = [],
        ?int $maxResponseBytes = null,
        ?string $userAgent = null,
    ): HttpResponse {
        $headers = $this->validateHeaders($headers);
        $userAgent = $this->validateUserAgent($userAgent ?? $this->userAgent);
        $bodyLimit = $maxResponseBytes ?? $this->maxResponseBytes;
        if ($bodyLimit < 1 || $bodyLimit > $this->maxResponseBytes) {
            throw new RemoteHttpException('INVALID_LIMIT', 'La limite de réponse HTTP est invalide.');
        }

        $currentUrl = $url;
        $visited = [];
        $redirects = 0;
        while (true) {
            $resolved = $this->guard->resolve($currentUrl);
            if (isset($visited[$resolved->url])) {
                throw new RemoteHttpException('REDIRECT_LOOP', 'Une boucle de redirection distante a été détectée.');
            }
            $visited[$resolved->url] = true;

            $transportResponse = $this->transport->send(new TransportRequest(
                $resolved->url,
                $resolved->host,
                $resolved->port,
                $resolved->addresses[0],
                $headers,
                $this->connectTimeoutMs,
                $this->timeoutMs,
                $bodyLimit,
                $userAgent,
            ));
            if (strlen($transportResponse->body) > $bodyLimit) {
                throw new RemoteHttpException('RESPONSE_TOO_LARGE', 'La réponse distante dépasse la taille autorisée.');
            }

            if (!$this->isRedirect($transportResponse->status)) {
                return new HttpResponse(
                    $transportResponse->status,
                    $transportResponse->headers,
                    $transportResponse->body,
                    $resolved->url,
                );
            }

            $location = $transportResponse->header('location');
            if ($location === null) {
                return new HttpResponse(
                    $transportResponse->status,
                    $transportResponse->headers,
                    $transportResponse->body,
                    $resolved->url,
                );
            }
            if ($redirects >= $this->maxRedirects) {
                throw new RemoteHttpException('TOO_MANY_REDIRECTS', 'Le nombre maximal de redirections est dépassé.');
            }
            ++$redirects;
            $currentUrl = $this->urlResolver->resolve($resolved->url, $location);
        }
    }

    public function validateUrl(string $url): string
    {
        return $this->guard->resolve($url)->url;
    }

    /** @param array<string, string> $headers
     *  @return array<string, string>
     */
    private function validateHeaders(array $headers): array
    {
        $validated = [];
        foreach ($headers as $name => $value) {
            $normalizedName = strtolower(trim($name));
            if (!in_array($normalizedName, self::ALLOWED_REQUEST_HEADERS, true)
                || strlen($value) > 4096
                || preg_match('/[\x00-\x1f\x7f]/', $value) === 1) {
                throw new RemoteHttpException('INVALID_HEADER', 'Un en-tête HTTP sortant est invalide.');
            }
            $validated[$normalizedName] = $value;
        }

        return $validated;
    }

    private function validateUserAgent(string $userAgent): string
    {
        $userAgent = trim($userAgent);
        if ($userAgent === '' || strlen($userAgent) > 512
            || preg_match('/[\x00-\x1f\x7f]/', $userAgent) === 1) {
            throw new RemoteHttpException('INVALID_USER_AGENT', 'Le User-Agent HTTP sortant est invalide.');
        }

        return $userAgent;
    }

    private function isRedirect(int $status): bool
    {
        return in_array($status, [301, 302, 303, 307, 308], true);
    }
}
