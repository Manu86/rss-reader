<?php

declare(strict_types=1);

namespace App\Exception;

use RuntimeException;

final class RemoteHttpException extends RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
