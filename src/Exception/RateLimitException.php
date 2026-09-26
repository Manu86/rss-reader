<?php

declare(strict_types=1);

namespace App\Exception;

final class RateLimitException extends ApiException
{
    public function __construct()
    {
        parent::__construct(429, 'TOO_MANY_ATTEMPTS', 'Trop de tentatives. Réessayez plus tard.');
    }
}
