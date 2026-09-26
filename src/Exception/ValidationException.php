<?php

declare(strict_types=1);

namespace App\Exception;

final class ValidationException extends ApiException
{
    /** @param array<string, string> $fields */
    public function __construct(array $fields, string $message = 'La requête contient des données invalides.')
    {
        parent::__construct(422, 'VALIDATION_ERROR', $message, $fields);
    }
}
