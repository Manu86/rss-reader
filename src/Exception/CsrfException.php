<?php

declare(strict_types=1);

namespace App\Exception;

final class CsrfException extends ApiException
{
    public function __construct()
    {
        parent::__construct(403, 'INVALID_CSRF_TOKEN', 'Jeton CSRF absent ou invalide.');
    }
}
