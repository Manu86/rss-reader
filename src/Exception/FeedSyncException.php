<?php

declare(strict_types=1);

namespace App\Exception;

final class FeedSyncException extends ApiException
{
    public static function remoteFailure(): self
    {
        return new self(502, 'FEED_FETCH_FAILED', 'Le flux distant n’a pas pu être récupéré.');
    }

    public static function invalidFeed(): self
    {
        return new self(422, 'INVALID_FEED', 'La ressource distante n’est pas un flux RSS 2.0 ou Atom valide.');
    }
}
