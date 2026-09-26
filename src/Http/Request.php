<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use App\Exception\ValidationException;
use JsonException;

final readonly class Request
{
    private const MAX_JSON_BYTES = 65_536;

    /** @param array<string, string> $headers
     *  @param array<string, mixed> $query
     *  @param array<string, UploadedFile> $files
     */
    public function __construct(
        public string $method,
        public string $path,
        private array $headers = [],
        private string $body = '',
        public string $remoteAddress = '',
        private array $query = [],
        private array $files = [],
    ) {}

    public static function fromGlobals(): self
    {
        $method = is_string($_SERVER['REQUEST_METHOD'] ?? null) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
        $uri = is_string($_SERVER['REQUEST_URI'] ?? null) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $queryString = parse_url($uri, PHP_URL_QUERY);
        $query = [];
        if (is_string($queryString)) {
            $parsedQuery = [];
            parse_str($queryString, $parsedQuery);
            foreach ($parsedQuery as $key => $value) {
                $query[(string) $key] = $value;
            }
        }
        $body = file_get_contents('php://input', false, null, 0, self::MAX_JSON_BYTES + 1);

        $files = [];
        foreach ($_FILES as $field => $upload) {
            if (!is_string($field) || !is_array($upload)) {
                continue;
            }
            $temporaryPath = $upload['tmp_name'] ?? null;
            $size = $upload['size'] ?? null;
            $error = $upload['error'] ?? null;
            if (is_string($temporaryPath) && is_int($size) && is_int($error)) {
                $files[$field] = new UploadedFile($temporaryPath, $size, $error);
            }
        }

        return new self(
            $method,
            is_string($path) ? $path : '/',
            self::headersFromServer($_SERVER),
            is_string($body) ? $body : '',
            is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : '',
            $query,
            $files,
        );
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    /** @return array<string, mixed> */
    public function json(): array
    {
        if (strlen($this->body) > self::MAX_JSON_BYTES) {
            throw new ApiException(413, 'REQUEST_TOO_LARGE', 'Le corps de la requête est trop volumineux.');
        }
        $contentType = $this->header('content-type');
        if ($contentType === null
            || !str_starts_with(strtolower($contentType), 'application/json')) {
            throw new ValidationException([], 'Le corps doit être envoyé en JSON.');
        }

        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new ValidationException([], 'Le corps JSON est invalide.');
        }
        if (!is_array($data) || array_is_list($data)) {
            throw new ValidationException([], 'Un objet JSON est requis.');
        }

        return $data;
    }

    /** @param array<string, mixed> $data
     *  @param list<string> $allowed
     */
    public function rejectUnknownFields(array $data, array $allowed): void
    {
        $unknown = array_diff(array_keys($data), $allowed);
        if ($unknown !== []) {
            throw new ValidationException([
                (string) reset($unknown) => 'Ce champ n’est pas accepté.',
            ]);
        }
    }

    /** @return array<string, mixed> */
    public function query(): array
    {
        return $this->query;
    }

    public function uploadedFile(string $field): ?UploadedFile
    {
        return $this->files[$field] ?? null;
    }

    /** @param array<string|int, mixed> $server
     *  @return array<string, string>
     */
    private static function headersFromServer(array $server): array
    {
        $headers = [];
        foreach ($server as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }
            if (str_starts_with($key, 'HTTP_')) {
                $name = strtolower(str_replace('_', '-', substr($key, 5)));
                $headers[$name] = $value;
            } elseif ($key === 'CONTENT_TYPE') {
                $headers['content-type'] = $value;
            }
        }

        return $headers;
    }
}
