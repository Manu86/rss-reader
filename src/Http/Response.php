<?php

declare(strict_types=1);

namespace App\Http;

use JsonException;

final readonly class Response
{
    /** @param array<string, string> $headers */
    public function __construct(
        public int $status,
        public string $body = '',
        public array $headers = [],
    ) {}

    /** @param array<string, mixed> $payload */
    public static function json(array $payload, int $status = 200): self
    {
        try {
            $body = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            $body = '{"error":{"code":"INTERNAL_ERROR","message":"Une erreur interne est survenue."}}';
            $status = 500;
        }

        return new self($status, $body, ['Content-Type' => 'application/json; charset=utf-8']);
    }

    public static function empty(int $status = 204): self
    {
        return new self($status);
    }

    /** @param array<string, string> $headers */
    public function withHeaders(array $headers): self
    {
        return new self($this->status, $this->body, array_merge($this->headers, $headers));
    }

    public function emit(): void
    {
        http_response_code($this->status);
        foreach ($this->headers as $name => $value) {
            header($name . ': ' . $value);
        }
        if ($this->status !== 204) {
            echo $this->body;
        }
    }
}
