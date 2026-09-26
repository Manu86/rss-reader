<?php

declare(strict_types=1);

namespace App\Exception;

final class AuthenticationException extends ApiException
{
    public function __construct(string $message = 'Identifiants invalides.')
    {
        parent::__construct(401, 'AUTHENTICATION_FAILED', $message);
    }
}
