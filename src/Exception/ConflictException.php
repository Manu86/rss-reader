<?php

declare(strict_types=1);

namespace App\Exception;

final class ConflictException extends ApiException
{
    public function __construct(string $code, string $message)
    {
        parent::__construct(409, $code, $message);
    }
}
