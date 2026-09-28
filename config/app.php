<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'production';
$rawSessionSecure = getenv('APP_SESSION_SECURE');
$sessionSecure = $rawSessionSecure === false
    ? true
    : filter_var($rawSessionSecure, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);

if ($sessionSecure === null) {
    throw new RuntimeException('APP_SESSION_SECURE doit être une valeur booléenne valide.');
}
if (strcasecmp(trim($environment), 'production') === 0) {
    $sessionSecure = true;
}

$optionalEnvironment = static function (string $name): ?string {
    $value = getenv($name);
    if (!is_string($value) || trim($value) === '') {
        return null;
    }

    return trim($value);
};

return [
    'environment' => $environment,
    'database_path' => getenv('APP_DATABASE_PATH') ?: dirname(__DIR__) . '/var/database/rss-reader.sqlite',
    'secret_file' => getenv('APP_SECRET_FILE') ?: dirname(__DIR__) . '/var/app.secret',
    'session_name' => getenv('APP_SESSION_NAME') ?: 'rss_reader_session',
    'session_lifetime' => (int) (getenv('APP_SESSION_LIFETIME') ?: 7200),
    'session_secure' => $sessionSecure,
    'remember_cookie_name' => getenv('APP_REMEMBER_COOKIE_NAME') ?: 'rss_reader_remember',
    'remember_lifetime' => (int) (getenv('APP_REMEMBER_LIFETIME') ?: 2592000),
    'http_connect_timeout_ms' => (int) (getenv('APP_HTTP_CONNECT_TIMEOUT_MS') ?: 3000),
    'http_timeout_ms' => (int) (getenv('APP_HTTP_TIMEOUT_MS') ?: 10000),
    'http_max_response_bytes' => (int) (getenv('APP_HTTP_MAX_RESPONSE_BYTES') ?: 5242880),
    'http_max_redirects' => (int) (getenv('APP_HTTP_MAX_REDIRECTS') ?: 5),
    'http_user_agent' => getenv('APP_HTTP_USER_AGENT') ?: 'RSSReader/1.0',
    'cron_lock_path' => getenv('APP_CRON_LOCK_PATH') ?: dirname(__DIR__) . '/var/tmp/feeds-refresh.lock',
    'media_path' => getenv('APP_MEDIA_PATH') ?: dirname(__DIR__) . '/var/media',
    'media_max_bytes' => (int) (getenv('APP_MEDIA_MAX_BYTES') ?: 2097152),
    'media_max_width' => (int) (getenv('APP_MEDIA_MAX_WIDTH') ?: 4096),
    'media_max_height' => (int) (getenv('APP_MEDIA_MAX_HEIGHT') ?: 4096),
    'media_max_pixels' => (int) (getenv('APP_MEDIA_MAX_PIXELS') ?: 16777216),
    'app_base_url' => $optionalEnvironment('APP_BASE_URL'),
    'mailer_dsn' => $optionalEnvironment('APP_MAILER_DSN'),
    'mail_from' => $optionalEnvironment('APP_MAIL_FROM'),
    'mail_from_name' => getenv('APP_MAIL_FROM_NAME') ?: 'RSS Reader',
];
