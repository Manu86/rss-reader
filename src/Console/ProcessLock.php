<?php

declare(strict_types=1);

namespace App\Console;

interface ProcessLock
{
    public function acquire(): bool;

    public function release(): void;
}
