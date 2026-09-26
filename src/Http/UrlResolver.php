<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\RemoteHttpException;

final class UrlResolver
{
    public function resolve(string $baseUrl, string $location): string
    {
        $location = trim($location);
        if ($location === '' || preg_match('/[\x00-\x1f\x7f]/', $location) === 1) {
            throw new RemoteHttpException('INVALID_REDIRECT', 'La redirection distante est invalide.');
        }

        $locationScheme = parse_url($location, PHP_URL_SCHEME);
        if (is_string($locationScheme)) {
            return $location;
        }

        $scheme = parse_url($baseUrl, PHP_URL_SCHEME);
        $host = parse_url($baseUrl, PHP_URL_HOST);
        $port = parse_url($baseUrl, PHP_URL_PORT);
        $basePath = parse_url($baseUrl, PHP_URL_PATH);
        if (!is_string($scheme) || !is_string($host) || ($port !== null && !is_int($port))) {
            throw new RemoteHttpException('INVALID_REDIRECT', 'La redirection distante est invalide.');
        }
        $authority = $scheme . '://' . $host . ($port === null ? '' : ':' . $port);
        if (str_starts_with($location, '//')) {
            return $scheme . ':' . $location;
        }
        if (str_starts_with($location, '#')) {
            return $baseUrl . $location;
        }

        $parts = parse_url($location);
        if (!is_array($parts)) {
            throw new RemoteHttpException('INVALID_REDIRECT', 'La redirection distante est invalide.');
        }
        $locationPath = $parts['path'] ?? '';
        if (!is_string($locationPath)) {
            throw new RemoteHttpException('INVALID_REDIRECT', 'La redirection distante est invalide.');
        }

        if ($locationPath === '') {
            $path = is_string($basePath) && $basePath !== '' ? $basePath : '/';
        } elseif (str_starts_with($locationPath, '/')) {
            $path = $this->removeDotSegments($locationPath);
        } else {
            $directory = is_string($basePath) ? preg_replace('#/[^/]*\z#', '/', $basePath) : '/';
            $path = $this->removeDotSegments((is_string($directory) ? $directory : '/') . $locationPath);
        }

        $query = isset($parts['query']) && is_string($parts['query']) ? '?' . $parts['query'] : '';
        $fragment = isset($parts['fragment']) && is_string($parts['fragment']) ? '#' . $parts['fragment'] : '';

        return $authority . $path . $query . $fragment;
    }

    private function removeDotSegments(string $path): string
    {
        $segments = [];
        foreach (explode('/', $path) as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
                continue;
            }
            $segments[] = $segment;
        }

        return '/' . implode('/', $segments);
    }
}
