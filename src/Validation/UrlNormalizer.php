<?php

declare(strict_types=1);

namespace App\Validation;

use App\Exception\ValidationException;

final class UrlNormalizer
{
    public function normalizeHttpUrl(string $url, string $field = 'feed_url'): string
    {
        $url = trim($url);
        if ($url === '' || strlen($url) > 2048 || preg_match('/[\x00-\x20\x7f]/', $url) === 1) {
            throw $this->invalid($field);
        }

        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !is_string($parts['scheme'])
            || !is_string($parts['host'])) {
            throw $this->invalid($field);
        }

        $scheme = strtolower($parts['scheme']);
        if (!in_array($scheme, ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])) {
            throw $this->invalid($field);
        }

        $host = $this->normalizeHost($parts['host'], $field);
        $port = $parts['port'] ?? null;
        if ($port !== null && (!is_int($port) || $port < 1 || $port > 65535)) {
            throw $this->invalid($field);
        }
        $includePort = $port !== null
            && !(($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443));

        $path = $parts['path'] ?? '/';
        if (!is_string($path) || $path === '') {
            $path = '/';
        }
        $query = isset($parts['query']) && is_string($parts['query'])
            ? '?' . $parts['query']
            : '';

        return $scheme . '://' . $host . ($includePort ? ':' . $port : '') . $path . $query;
    }

    private function normalizeHost(string $host, string $field): string
    {
        $host = strtolower(rtrim($host, '.'));
        if ($host === '') {
            throw $this->invalid($field);
        }

        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $address = substr($host, 1, -1);
            if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
                throw $this->invalid($field);
            }

            return '[' . $address . ']';
        }

        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return $host;
        }

        if (function_exists('idn_to_ascii')) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);
            if ($ascii === false) {
                throw $this->invalid($field);
            }
            $host = strtolower($ascii);
        }

        if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false) {
            throw $this->invalid($field);
        }

        return $host;
    }

    private function invalid(string $field): ValidationException
    {
        return new ValidationException([
            $field => 'Une URL HTTP ou HTTPS absolue et valide est requise.',
        ]);
    }
}
