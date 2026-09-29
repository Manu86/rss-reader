<?php

declare(strict_types=1);

namespace App\Exception;

/**
 * Une synchronisation est déjà en cours pour ce flux : la requête HTTP, la
 * synchronisation manuelle ou le cron se chevauchent, ou deux appels quasi
 * simultanés. Un flux déjà verrouillé n’est pas une panne : le cron compte ce
 * cas à part et l’API répond 409.
 */
final class FeedBusyException extends ApiException
{
    public static function alreadyRefreshing(): self
    {
        return new self(409, 'FEED_BUSY', 'Ce flux est déjà en cours d’actualisation.');
    }
}
