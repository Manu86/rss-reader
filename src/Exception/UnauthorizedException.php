<?php

declare(strict_types=1);

namespace App\Exception;

final class UnauthorizedException extends ApiException
{
    public function __construct()
    {
        parent::__construct(401, 'AUTHENTICATION_REQUIRED', 'Authentification requise.');
    }
}
