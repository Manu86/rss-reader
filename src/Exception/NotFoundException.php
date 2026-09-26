<?php

declare(strict_types=1);

namespace App\Exception;

final class NotFoundException extends ApiException
{
    public function __construct(string $message = 'Ressource introuvable.')
    {
        parent::__construct(404, 'NOT_FOUND', $message);
    }
}
