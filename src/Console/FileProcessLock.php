<?php

declare(strict_types=1);

namespace App\Console;

use RuntimeException;

final class FileProcessLock implements ProcessLock
{
    /** @var resource|null */
    private mixed $handle = null;

    public function __construct(private readonly string $path) {}

    public function acquire(): bool
    {
        if (is_resource($this->handle)) {
            return true;
        }
        $directory = dirname($this->path);
        if (!is_dir($directory) && !mkdir($directory, 0700, true) && !is_dir($directory)) {
            throw new RuntimeException('Impossible de créer le répertoire de verrouillage.');
        }
        $handle = @fopen($this->path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Impossible d’ouvrir le verrou de synchronisation.');
        }
        @chmod($this->path, 0600);
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return false;
        }
        $this->handle = $handle;

        return true;
    }

    public function release(): void
    {
        if (!is_resource($this->handle)) {
            return;
        }
        flock($this->handle, LOCK_UN);
        fclose($this->handle);
        $this->handle = null;
    }

    public function __destruct()
    {
        $this->release();
    }
}
