<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

class ApiException extends RuntimeException
{
    /** @param array<string, string> $fields */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $fields = [],
    ) {
        parent::__construct($message);
    }
}
