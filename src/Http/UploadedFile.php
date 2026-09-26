<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\ApiException;
use App\Exception\ValidationException;

final readonly class UploadedFile
{
    public function __construct(
        private string $temporaryPath,
        private int $size,
        private int $error,
    ) {}

    public function contents(int $maximumBytes): string
    {
        if (in_array($this->error, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            || $this->size > $maximumBytes) {
            throw new ApiException(413, 'OPML_TOO_LARGE', 'Le fichier OPML est trop volumineux.');
        }
        if ($this->error !== UPLOAD_ERR_OK || $this->size < 0 || !is_file($this->temporaryPath)) {
            throw new ValidationException(['file' => 'Le fichier OPML n’a pas pu être reçu.']);
        }

        $handle = @fopen($this->temporaryPath, 'rb');
        if ($handle === false) {
            throw new ValidationException(['file' => 'Le fichier OPML n’a pas pu être lu.']);
        }
        try {
            $contents = stream_get_contents($handle, $maximumBytes + 1);
        } finally {
            fclose($handle);
        }
        if (!is_string($contents)) {
            throw new ValidationException(['file' => 'Le fichier OPML n’a pas pu être lu.']);
        }
        if (strlen($contents) > $maximumBytes) {
            throw new ApiException(413, 'OPML_TOO_LARGE', 'Le fichier OPML est trop volumineux.');
        }

        return $contents;
    }
}
