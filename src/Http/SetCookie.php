<?php

declare(strict_types=1);

namespace App\Http;

final readonly class SetCookie
{
    public function __construct(
        public string $name,
        public string $value,
        public int $maxAge,
        public bool $secure,
    ) {}

    /** A `maxAge` of zero asks the browser to drop the cookie immediately. */
    public static function cleared(string $name, bool $secure): self
    {
        return new self($name, '', 0, $secure);
    }

    /**
     * The value must already be a valid cookie value: it is emitted verbatim,
     * so callers must never pass unvalidated input.
     */
    public function toHeader(): string
    {
        $parts = [
            $this->name . '=' . $this->value,
            'Path=/',
            'Max-Age=' . $this->maxAge,
            'HttpOnly',
            'SameSite=Lax',
        ];
        if ($this->secure) {
            $parts[] = 'Secure';
        }

        return implode('; ', $parts);
    }
}
