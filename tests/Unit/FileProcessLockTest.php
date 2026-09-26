<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Console\FileProcessLock;
use PHPUnit\Framework\TestCase;

final class FileProcessLockTest extends TestCase
{
    public function testOnlyOneProcessLockCanBeHeldAndItCanBeReacquiredAfterRelease(): void
    {
        $path = sys_get_temp_dir() . '/rss-reader-lock-' . bin2hex(random_bytes(8)) . '.lock';
        $first = new FileProcessLock($path);
        $second = new FileProcessLock($path);

        try {
            self::assertTrue($first->acquire());
            self::assertTrue($first->acquire());
            self::assertFalse($second->acquire());
            $first->release();
            self::assertTrue($second->acquire());
        } finally {
            $first->release();
            $second->release();
            if (is_file($path)) {
                unlink($path);
            }
        }
    }
}
