<?php

declare(strict_types=1);

namespace App\Storage;

use App\Model\StoredMedia;
use RuntimeException;

final readonly class FileMediaStorage implements MediaStorage
{
    /** @var array<string, string> */
    private const CONTENT_TYPES = [
        'jpg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
    ];

    public function __construct(private string $root) {}

    public function store(int $userId, string $content, string $extension): string
    {
        $extension = strtolower($extension);
        if ($userId < 1 || !isset(self::CONTENT_TYPES[$extension])) {
            throw new RuntimeException('Le type de média local est invalide.');
        }

        for ($attempt = 0; $attempt < 3; ++$attempt) {
            $identifier = bin2hex(random_bytes(16));
            $key = sprintf('u%d/%s/%s.%s', $userId, substr($identifier, 0, 2), $identifier, $extension);
            $path = $this->path($userId, $key);
            if ($path === null) {
                throw new RuntimeException('La clé de média générée est invalide.');
            }
            $directory = dirname($path);
            if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
                throw new RuntimeException('Impossible de créer le répertoire de médias.');
            }
            $handle = @fopen($path, 'x');
            if ($handle === false) {
                continue;
            }
            try {
                $offset = 0;
                $length = strlen($content);
                while ($offset < $length) {
                    $written = fwrite($handle, substr($content, $offset));
                    if ($written === false || $written === 0) {
                        throw new RuntimeException('Impossible d’écrire le média local.');
                    }
                    $offset += $written;
                }
            } catch (\Throwable $exception) {
                fclose($handle);
                @unlink($path);
                throw $exception;
            }
            fclose($handle);
            // The inherited ACL grants app and web runtimes access; 0640 keeps
            // content readable to them without making it world-readable.
            @chmod($path, 0640);

            return $key;
        }

        throw new RuntimeException('Impossible de créer un fichier média unique.');
    }

    public function read(int $userId, string $key): ?StoredMedia
    {
        $path = $this->path($userId, $key);
        if ($path === null || !is_file($path)) {
            return null;
        }
        $content = @file_get_contents($path);
        if (!is_string($content)) {
            return null;
        }
        $extension = pathinfo($path, PATHINFO_EXTENSION);
        $contentType = self::CONTENT_TYPES[$extension] ?? null;

        return $contentType === null ? null : new StoredMedia($content, $contentType);
    }

    public function delete(int $userId, string $key): void
    {
        $path = $this->path($userId, $key);
        if ($path !== null && is_file($path) && !@unlink($path)) {
            throw new RuntimeException('Impossible de supprimer le média local.');
        }
    }

    private function path(int $userId, string $key): ?string
    {
        if ($userId < 1 || preg_match(
            '/\Au' . preg_quote((string) $userId, '/') . '\/[a-f0-9]{2}\/[a-f0-9]{32}\.(?:jpg|png|gif|webp|ico)\z/',
            $key,
        ) !== 1) {
            return null;
        }

        return rtrim($this->root, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR
            . str_replace('/', DIRECTORY_SEPARATOR, $key);
    }
}
