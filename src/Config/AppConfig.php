<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final readonly class AppConfig
{
    public function __construct(
        public string $databasePath,
        public string $secretFile,
        public string $sessionName,
        public int $sessionLifetime,
        public bool $sessionSecure,
        public string $rememberCookieName,
        public int $rememberLifetime,
        public int $httpConnectTimeoutMs,
        public int $httpTimeoutMs,
        public int $httpMaxResponseBytes,
        public int $httpMaxRedirects,
        public string $httpUserAgent,
        public string $articlePageUserAgent,
        public string $cronLockPath,
        public string $mediaPath,
        public int $mediaMaxBytes,
        public int $mediaMaxWidth,
        public int $mediaMaxHeight,
        public int $mediaMaxPixels,
        public ?string $appBaseUrl,
        public ?string $mailerDsn,
        public ?string $mailFrom,
        public string $mailFromName,
    ) {
        if ($this->sessionLifetime < 300) {
            throw new RuntimeException('La durée de session doit être d’au moins 300 secondes.');
        }
        if ($this->rememberLifetime < 3600) {
            throw new RuntimeException('La durée du cookie de mémoire doit être d’au moins 3600 secondes.');
        }
        if (preg_match('/\A[A-Za-z0-9_-]+\z/', $this->rememberCookieName) !== 1) {
            throw new RuntimeException('Le nom du cookie de mémoire est invalide.');
        }
        if ($this->httpConnectTimeoutMs < 100 || $this->httpTimeoutMs < $this->httpConnectTimeoutMs) {
            throw new RuntimeException('Les délais HTTP configurés sont invalides.');
        }
        if ($this->httpMaxResponseBytes < 1024 || $this->httpMaxRedirects < 0 || $this->httpMaxRedirects > 10) {
            throw new RuntimeException('Les limites HTTP configurées sont invalides.');
        }
        if ($this->mediaMaxBytes < 1024 || $this->mediaMaxBytes > $this->httpMaxResponseBytes
            || $this->mediaMaxWidth < 1 || $this->mediaMaxHeight < 1 || $this->mediaMaxPixels < 1) {
            throw new RuntimeException('Les limites de médias configurées sont invalides.');
        }
        if ($this->mailerDsn !== null) {
            if ($this->mailFrom === null || filter_var($this->mailFrom, FILTER_VALIDATE_EMAIL) === false) {
                throw new RuntimeException('APP_MAIL_FROM doit être une adresse email valide.');
            }
            if ($this->appBaseUrl === null || !$this->isSafeBaseUrl($this->appBaseUrl)) {
                throw new RuntimeException('APP_BASE_URL doit être une URL HTTP(S) absolue sans identifiants.');
            }
        }
    }

    public static function load(string $file): self
    {
        /** @var mixed $raw */
        $raw = require $file;
        if (!is_array($raw)) {
            throw new RuntimeException('La configuration de l’application est invalide.');
        }

        return new self(
            self::stringValue($raw, 'database_path'),
            self::stringValue($raw, 'secret_file'),
            self::stringValue($raw, 'session_name'),
            self::intValue($raw, 'session_lifetime'),
            self::boolValue($raw, 'session_secure'),
            self::stringValue($raw, 'remember_cookie_name'),
            self::intValue($raw, 'remember_lifetime'),
            self::intValue($raw, 'http_connect_timeout_ms'),
            self::intValue($raw, 'http_timeout_ms'),
            self::intValue($raw, 'http_max_response_bytes'),
            self::intValue($raw, 'http_max_redirects'),
            self::stringValue($raw, 'http_user_agent'),
            self::stringValue($raw, 'article_page_user_agent'),
            self::stringValue($raw, 'cron_lock_path'),
            self::stringValue($raw, 'media_path'),
            self::intValue($raw, 'media_max_bytes'),
            self::intValue($raw, 'media_max_width'),
            self::intValue($raw, 'media_max_height'),
            self::intValue($raw, 'media_max_pixels'),
            self::nullableStringValue($raw, 'app_base_url'),
            self::nullableStringValue($raw, 'mailer_dsn'),
            self::nullableStringValue($raw, 'mail_from'),
            self::stringValue($raw, 'mail_from_name'),
        );
    }

    public function readSecret(): string
    {
        $environmentSecret = getenv('APP_SECRET');
        if (is_string($environmentSecret) && strlen($environmentSecret) >= 32) {
            return $environmentSecret;
        }

        $secret = @file_get_contents($this->secretFile);
        if (!is_string($secret) || strlen(trim($secret)) < 32) {
            throw new RuntimeException('Secret applicatif absent. Exécutez bin/console app:install.');
        }

        return trim($secret);
    }

    /** @param array<mixed> $values */
    private static function stringValue(array $values, string $key): string
    {
        $value = $values[$key] ?? null;
        if (!is_string($value) || $value === '') {
            throw new RuntimeException(sprintf('Configuration invalide : %s.', $key));
        }

        return $value;
    }

    /** @param array<mixed> $values */
    private static function intValue(array $values, string $key): int
    {
        $value = $values[$key] ?? null;
        if (!is_int($value)) {
            throw new RuntimeException(sprintf('Configuration invalide : %s.', $key));
        }

        return $value;
    }

    /** @param array<mixed> $values */
    private static function boolValue(array $values, string $key): bool
    {
        $value = $values[$key] ?? null;
        if (!is_bool($value)) {
            throw new RuntimeException(sprintf('Configuration invalide : %s.', $key));
        }

        return $value;
    }

    /** @param array<mixed> $values */
    private static function nullableStringValue(array $values, string $key): ?string
    {
        $value = $values[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (!is_string($value) || trim($value) === '') {
            throw new RuntimeException(sprintf('Configuration invalide : %s.', $key));
        }

        return trim($value);
    }

    private function isSafeBaseUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (!is_array($parts)
            || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower((string) $parts['scheme']), ['http', 'https'], true)
            || isset($parts['user'])
            || isset($parts['pass'])
            || isset($parts['query'])
            || isset($parts['fragment'])) {
            return false;
        }

        return true;
    }
}
