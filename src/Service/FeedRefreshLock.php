<?php

declare(strict_types=1);

namespace App\Service;

use App\Console\FileProcessLock;

/**
 * Verrou exclusif et non bloquant par flux. La synchronisation passe par un
 * point unique, quel que soit le point d'entrée : sans ce verrou, une
 * actualisation HTTP pouvait se chevaucher avec le cron ou avec un autre appel,
 * et la dernière écriture l'emportait sur le validateur HTTP (ETag,
 * Last-Modified) enregistré par la première, alors que les articles importés
 * ne correspondaient plus à ce validateur.
 */
final class FeedRefreshLock
{
    /** @var array<int, FileProcessLock> */
    private array $locks = [];

    public function __construct(private readonly string $lockDirectory) {}

    public function acquire(int $feedId): bool
    {
        $lock = $this->locks[$feedId] ??= new FileProcessLock(
            sprintf('%s/feed-%d.lock', rtrim($this->lockDirectory, '/'), $feedId),
        );

        return $lock->acquire();
    }

    public function release(int $feedId): void
    {
        $this->locks[$feedId]?->release();
    }
}
