<?php

declare(strict_types=1);

namespace App\Security;

use App\Exception\RemoteHttpException;
use App\Exception\ValidationException;
use App\Http\DnsResolver;
use App\Http\ResolvedUrl;
use App\Validation\UrlNormalizer;

final readonly class RemoteUrlGuard
{
    public function __construct(
        private UrlNormalizer $normalizer,
        private DnsResolver $dns,
        private IpAddressValidator $addresses,
    ) {}

    public function resolve(string $url): ResolvedUrl
    {
        try {
            $normalized = $this->normalizer->normalizeHttpUrl($url, 'url');
        } catch (ValidationException) {
            throw new RemoteHttpException('UNSAFE_URL', 'L’URL distante est invalide ou interdite.');
        }

        $scheme = parse_url($normalized, PHP_URL_SCHEME);
        $host = parse_url($normalized, PHP_URL_HOST);
        $port = parse_url($normalized, PHP_URL_PORT);
        if (!is_string($scheme) || !is_string($host) || ($port !== null && !is_int($port))) {
            throw new RemoteHttpException('UNSAFE_URL', 'L’URL distante est invalide ou interdite.');
        }

        $resolvedAddresses = $this->dns->resolve($host);
        if ($resolvedAddresses === []) {
            throw new RemoteHttpException('DNS_FAILED', 'Le nom d’hôte distant ne peut pas être résolu.');
        }
        foreach ($resolvedAddresses as $address) {
            if (!$this->addresses->isPublic($address)) {
                throw new RemoteHttpException('UNSAFE_ADDRESS', 'La destination distante est interdite.');
            }
        }

        return new ResolvedUrl(
            $normalized,
            $scheme,
            $host,
            $port ?? ($scheme === 'https' ? 443 : 80),
            $resolvedAddresses,
        );
    }
}
