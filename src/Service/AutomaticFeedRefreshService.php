<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use App\Exception\FeedBusyException;
use App\Repository\FeedRepository;
use Throwable;

final readonly class AutomaticFeedRefreshService
{
    public function __construct(
        private FeedRepository $feeds,
        private FeedRefresher $refresher,
    ) {}

    /** @return array{total: int, successful: int, failed: int, skipped: int, imported_articles: int, not_modified: int} */
    public function run(): array
    {
        $summary = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
            'skipped' => 0,
            'imported_articles' => 0,
            'not_modified' => 0,
        ];
        foreach ($this->feeds->listActiveForEnabledUsers() as $feed) {
            ++$summary['total'];
            try {
                $result = $this->refresher->refresh($feed);
                ++$summary['successful'];
                $summary['imported_articles'] += $result['imported_articles'];
                if ($result['not_modified']) {
                    ++$summary['not_modified'];
                }
            } catch (FeedBusyException) {
                // Un flux déjà en cours d'actualisation n'est pas en panne : il
                // est ignoré, sinon le cron signalerait un échec pour un flux
                // qui vient d'être synchronisé.
                ++$summary['skipped'];
            } catch (ApiException $exception) {
                ++$summary['failed'];
                error_log(sprintf(
                    'Automatic feed refresh failed [user:%d feed:%d code:%s]',
                    $feed->userId,
                    $feed->id,
                    $exception->errorCode,
                ));
            } catch (Throwable) {
                ++$summary['failed'];
                error_log(sprintf(
                    'Unexpected automatic feed refresh failure [user:%d feed:%d]',
                    $feed->userId,
                    $feed->id,
                ));
            }
        }

        return $summary;
    }
}
