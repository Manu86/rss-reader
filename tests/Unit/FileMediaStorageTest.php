<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Storage\FileMediaStorage;
use PHPUnit\Framework\TestCase;

final class FileMediaStorageTest extends TestCase
{
    public function testStorageUsesSafeKeysAndRejectsCrossUserAndTraversalPaths(): void
    {
        $root = sys_get_temp_dir() . '/rss-reader-media-' . bin2hex(random_bytes(8));
        $storage = new FileMediaStorage($root);

        try {
            $key = $storage->store(12, 'image-content', 'png');
            self::assertMatchesRegularExpression('#\Au12/[a-f0-9]{2}/[a-f0-9]{32}\.png\z#', $key);
            self::assertSame('image-content', $storage->read(12, $key)?->content);
            self::assertNull($storage->read(13, $key));
            self::assertNull($storage->read(12, 'u12/aa/../../secret.png'));

            $storage->delete(12, 'u12/aa/../../secret.png');
            self::assertNotNull($storage->read(12, $key));
            $storage->delete(12, $key);
            self::assertNull($storage->read(12, $key));
        } finally {
            if (isset($key)) {
                $directory = dirname($root . '/' . $key);
                if (is_file($root . '/' . $key)) {
                    unlink($root . '/' . $key);
                }
                if (is_dir($directory)) {
                    rmdir($directory);
                }
                $userDirectory = $root . '/u12';
                if (is_dir($userDirectory)) {
                    rmdir($userDirectory);
                }
            }
            if (is_dir($root)) {
                rmdir($root);
            }
        }
    }
}
