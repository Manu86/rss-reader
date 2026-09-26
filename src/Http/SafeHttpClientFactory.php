<?php

declare(strict_types=1);

namespace App\Http;

use App\Config\AppConfig;
use App\Security\IpAddressValidator;
use App\Security\RemoteUrlGuard;
use App\Validation\UrlNormalizer;

final class SafeHttpClientFactory
{
    public static function create(AppConfig $config): SafeHttpClient
    {
        return new SafeHttpClient(
            new RemoteUrlGuard(
                new UrlNormalizer(),
                new SystemDnsResolver(),
                new IpAddressValidator(),
            ),
            new CurlHttpTransport(),
            new UrlResolver(),
            $config->httpConnectTimeoutMs,
            $config->httpTimeoutMs,
            $config->httpMaxResponseBytes,
            $config->httpMaxRedirects,
            $config->httpUserAgent,
        );
    }
}
