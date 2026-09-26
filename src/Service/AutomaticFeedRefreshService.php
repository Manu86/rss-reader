<?php

declare(strict_types=1);

namespace App\Service;

use App\Exception\ApiException;
use App\Repository\FeedRepository;
use Throwable;

final readonly class AutomaticFeedRefreshService
{
    public function __construct(
        private FeedRepository $feeds,
        private FeedRefresher $refresher,
    ) {}

    /** @return array{total: int, successful: int, failed: int, imported_articles: int, not_modified: int} */
    public function run(): array
    {
        $summary = [
            'total' => 0,
            'successful' => 0,
            'failed' => 0,
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
