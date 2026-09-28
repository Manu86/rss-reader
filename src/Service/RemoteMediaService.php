<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\InvalidMediaException;
use App\Exception\RemoteHttpException;
use App\Http\SafeHttpClient;
use App\Http\UrlResolver;
use App\Storage\MediaStorage;

final readonly class RemoteMediaService
{
    private const MAX_ARTICLE_HTML_BYTES = 1_000_000;

    /** @var array<string, string> */
    private const MIME_EXTENSIONS = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/gif' => 'gif',
        'image/webp' => 'webp',
        'image/x-icon' => 'ico',
        'image/vnd.microsoft.icon' => 'ico',
    ];

    public function __construct(
        private SafeHttpClient $http,
        private MediaStorage $storage,
        private int $maxBytes,
        private int $maxWidth,
        private int $maxHeight,
        private int $maxPixels,
    ) {}

    public function download(int $userId, string $url): string
    {
        $response = $this->http->get($url, ['accept' => 'image/webp,image/png,image/jpeg,image/gif,image/x-icon'], $this->maxBytes);
        if ($response->status < 200 || $response->status >= 300) {
            throw new InvalidMediaException('Le média distant n’est pas disponible.');
        }

        [$actualMime, $width, $height] = $this->inspect($response->body);
        $declaredMime = $this->mime($response->header('content-type'));
        if ($declaredMime !== null && !$this->mimeMatches($declaredMime, $actualMime)) {
            throw new InvalidMediaException('Le type déclaré du média ne correspond pas à son contenu.');
        }
        if ($width < 1 || $height < 1 || $width > $this->maxWidth || $height > $this->maxHeight
            || $width * $height > $this->maxPixels) {
            throw new InvalidMediaException('Les dimensions du média dépassent les limites autorisées.');
        }
        $extension = self::MIME_EXTENSIONS[$actualMime] ?? null;
        if ($extension === null) {
            throw new InvalidMediaException('Le format du média n’est pas pris en charge.');
        }

        return $this->storage->store($userId, $response->body, $extension);
    }

    public function downloadArticleImage(
        int $userId,
        ?string $feedImageUrl,
        ?string $articleUrl,
    ): string {
        if ($feedImageUrl !== null) {
            return $this->download($userId, $feedImageUrl);
        }
        if ($articleUrl === null) {
            throw new InvalidMediaException('Aucune image distante n’est disponible.');
        }

        $response = $this->http->get(
            $articleUrl,
            ['accept' => 'text/html,application/xhtml+xml;q=0.9'],
            self::MAX_ARTICLE_HTML_BYTES,
        );
        if ($response->status < 200 || $response->status >= 300 || !$this->isHtml($response->header('content-type'))) {
            throw new InvalidMediaException('La page de l’article n’est pas disponible.');
        }

        $parser = new ArticleImageMetadataParser(new UrlResolver());
        foreach ($parser->parse($response->body, $response->finalUrl) as $candidate) {
            try {
                return $this->download($userId, $candidate);
            } catch (InvalidMediaException|RemoteHttpException) {
                continue;
            }
        }

        throw new InvalidMediaException('La page de l’article ne fournit aucune image valide.');
    }

    public function discard(int $userId, string $key): void
    {
        $this->storage->delete($userId, $key);
    }

    /** @return array{string, int, int} */
    private function inspect(string $content): array
    {
        if ($content === '' || strlen($content) > $this->maxBytes) {
            throw new InvalidMediaException('La taille du média est invalide.');
        }
        $info = @getimagesizefromstring($content);
        if (is_array($info)) {
            return [strtolower($info['mime']), $info[0], $info[1]];
        }

        $ico = $this->inspectIcon($content);
        if ($ico !== null) {
            return ['image/x-icon', $ico[0], $ico[1]];
        }

        throw new InvalidMediaException('Le contenu distant n’est pas une image valide.');
    }

    /** @return array{int, int}|null */
    private function inspectIcon(string $content): ?array
    {
        if (strlen($content) < 22 || substr($content, 0, 4) !== "\x00\x00\x01\x00") {
            return null;
        }
        $header = unpack('vcount', substr($content, 4, 2));
        $count = is_array($header) ? ($header['count'] ?? 0) : 0;
        if (!is_int($count) || $count < 1 || $count > 256 || strlen($content) < 6 + $count * 16) {
            return null;
        }
        $entry = unpack('Vbytes/Voffset', substr($content, 14, 8));
        if (!is_array($entry)) {
            return null;
        }
        $bytes = $entry['bytes'] ?? 0;
        $offset = $entry['offset'] ?? 0;
        if (!is_int($bytes) || !is_int($offset) || $bytes < 8 || $offset < 6 + $count * 16
            || $offset > strlen($content) - $bytes) {
            return null;
        }
        $image = substr($content, $offset, $bytes);
        if (str_starts_with($image, "\x89PNG\r\n\x1a\n")) {
            $info = @getimagesizefromstring($image);
            if (!is_array($info)) {
                return null;
            }

            return [$info[0], $info[1]];
        }
        if (strlen($image) < 40) {
            return null;
        }
        $dib = unpack('Vsize/Vwidth/Vheight', substr($image, 0, 12));
        if (!is_array($dib) || ($dib['size'] ?? 0) < 40 || ($dib['width'] ?? 0) < 1 || ($dib['height'] ?? 0) < 2) {
            return null;
        }

        return [ord($content[6]) ?: 256, ord($content[7]) ?: 256];
    }

    private function mime(?string $contentType): ?string
    {
        if ($contentType === null || trim($contentType) === '') {
            return null;
        }

        return strtolower(trim(explode(';', $contentType, 2)[0]));
    }

    private function mimeMatches(string $declared, string $actual): bool
    {
        if ($declared === $actual) {
            return true;
        }

        return in_array($declared, ['image/x-icon', 'image/vnd.microsoft.icon'], true)
            && in_array($actual, ['image/x-icon', 'image/vnd.microsoft.icon'], true);
    }

    private function isHtml(?string $contentType): bool
    {
        if ($contentType === null) {
            return true;
        }

        return in_array($this->mime($contentType), ['text/html', 'application/xhtml+xml'], true);
    }
}
