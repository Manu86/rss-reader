<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Config\AppConfig;
use PHPUnit\Framework\TestCase;

final class AppConfigTest extends TestCase
{
    public function testSecureCookieCanBeDisabledExplicitlyForLocalHttp(): void
    {
        $config = $this->loadConfigWith([
            'APP_ENV' => 'development',
            'APP_SESSION_SECURE' => '0',
        ]);

        self::assertFalse($config->sessionSecure);
        self::assertSame(3000, $config->httpConnectTimeoutMs);
        self::assertSame(10000, $config->httpTimeoutMs);
        self::assertSame(5_242_880, $config->httpMaxResponseBytes);
        self::assertSame(5, $config->httpMaxRedirects);
        self::assertSame('RSSReader/1.0', $config->httpUserAgent);
        self::assertStringEndsWith('/var/tmp/feeds-refresh.lock', $config->cronLockPath);
        self::assertStringEndsWith('/var/media', $config->mediaPath);
        self::assertSame(2_097_152, $config->mediaMaxBytes);
        self::assertSame(4096, $config->mediaMaxWidth);
        self::assertSame(4096, $config->mediaMaxHeight);
        self::assertSame(16_777_216, $config->mediaMaxPixels);
        self::assertNull($config->mailerDsn);
        self::assertNull($config->mailFrom);
        self::assertNull($config->appBaseUrl);
        self::assertSame('RSS Reader', $config->mailFromName);
    }

    public function testProductionForcesSecureCookieOn(): void
    {
        $config = $this->loadConfigWith([
            'APP_ENV' => 'production',
            'APP_SESSION_SECURE' => '0',
        ]);

        self::assertTrue($config->sessionSecure);
    }

    public function testInvalidSecureCookieConfigurationIsRejected(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('APP_SESSION_SECURE doit être une valeur booléenne valide.');

        $this->loadConfigWith([
            'APP_ENV' => 'development',
            'APP_SESSION_SECURE' => 'maybe',
        ]);
    }

    public function testSecureCookieDefaultsToEnabledInProduction(): void
    {
        $config = $this->loadConfigWith(['APP_ENV' => 'production']);

        self::assertTrue($config->sessionSecure);
    }

    public function testMailerConfigurationIsLoaded(): void
    {
        $config = $this->loadConfigWith([
            'APP_ENV' => 'development',
            'APP_SESSION_SECURE' => '0',
            'APP_MAILER_DSN' => 'smtp://user:password@smtp.example.org:587',
            'APP_MAIL_FROM' => 'reader@example.org',
            'APP_MAIL_FROM_NAME' => 'Mon lecteur',
            'APP_BASE_URL' => 'http://reader.example.org',
        ]);

        self::assertSame('smtp://user:password@smtp.example.org:587', $config->mailerDsn);
        self::assertSame('reader@example.org', $config->mailFrom);
        self::assertSame('Mon lecteur', $config->mailFromName);
        self::assertSame('http://reader.example.org', $config->appBaseUrl);
    }

    public function testMailerRequiresValidSenderAndBaseUrl(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->loadConfigWith([
            'APP_ENV' => 'development',
            'APP_SESSION_SECURE' => '0',
            'APP_MAILER_DSN' => 'smtp://smtp.example.org',
            'APP_MAIL_FROM' => 'invalid',
            'APP_BASE_URL' => 'http://reader.example.org',
        ]);
    }

    /** @param array<string, string> $overrides */
    private function loadConfigWith(array $overrides): AppConfig
    {
        $keys = [
            'APP_ENV',
            'APP_SESSION_SECURE',
            'APP_MAILER_DSN',
            'APP_MAIL_FROM',
            'APP_MAIL_FROM_NAME',
            'APP_BASE_URL',
        ];
        $previous = [];
        foreach ($keys as $key) {
            $value = getenv($key);
            $previous[$key] = is_string($value) ? $value : null;
        }

        try {
            foreach ($keys as $key) {
                if (array_key_exists($key, $overrides)) {
                    putenv($key . '=' . $overrides[$key]);
                } else {
                    putenv($key);
                }
            }

            return AppConfig::load(dirname(__DIR__, 2) . '/config/app.php');
        } finally {
            foreach ($previous as $key => $value) {
                if ($value === null) {
                    putenv($key);
                } else {
                    putenv($key . '=' . $value);
                }
            }
        }
    }
}
