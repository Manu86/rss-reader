<?php

declare(strict_types=1);

namespace App\Service;

use App\Model\Article;
use App\Model\StoredMedia;
use App\Repository\ArticleRepository;
use App\Storage\MediaStorage;

final readonly class StoredRecommendationImageProvider implements RecommendationImageProvider
{
    private const MAX_SOURCE_BYTES = 2_097_152;
    private const MAX_SOURCE_PIXELS = 16_777_216;
    private const THUMBNAIL_SIZE = 88;
    private const MAX_THUMBNAIL_BYTES = 100_000;

    public function __construct(
        private ArticleRepository $articles,
        private MediaStorage $storage,
    ) {}

    public function forArticle(int $userId, Article $article): ?StoredMedia
    {
        if (!$article->hasImage) {
            return null;
        }
        $path = $this->articles->findImagePathOwned($article->id, $userId);

        if ($path === null) {
            return null;
        }
        $media = $this->storage->read($userId, $path);

        return $media === null ? null : $this->thumbnail($media);
    }

    private function thumbnail(StoredMedia $media): ?StoredMedia
    {
        if ($media->content === '' || strlen($media->content) > self::MAX_SOURCE_BYTES) {
            return null;
        }
        $info = @getimagesizefromstring($media->content);
        if (!is_array($info)) {
            return null;
        }
        $width = $info[0];
        $height = $info[1];
        if ($width < 1 || $height < 1 || $width * $height > self::MAX_SOURCE_PIXELS) {
            return null;
        }
        $source = @imagecreatefromstring($media->content);
        if ($source === false) {
            return null;
        }
        $thumbnail = imagecreatetruecolor(self::THUMBNAIL_SIZE, self::THUMBNAIL_SIZE);
        if ($thumbnail === false) {
            imagedestroy($source);

            return null;
        }
        try {
            $white = imagecolorallocate($thumbnail, 255, 255, 255);
            if ($white === false) {
                return null;
            }
            imagefill($thumbnail, 0, 0, $white);
            $cropSize = min($width, $height);
            $sourceX = (int) floor(($width - $cropSize) / 2);
            $sourceY = (int) floor(($height - $cropSize) / 2);
            if (!imagecopyresampled(
                $thumbnail,
                $source,
                0,
                0,
                $sourceX,
                $sourceY,
                self::THUMBNAIL_SIZE,
                self::THUMBNAIL_SIZE,
                $cropSize,
                $cropSize,
            )) {
                return null;
            }
            foreach ([78, 65, 50, 35] as $quality) {
                ob_start();
                $encoded = imagejpeg($thumbnail, null, $quality);
                $content = ob_get_clean();
                if ($encoded && is_string($content) && $content !== ''
                    && strlen($content) <= self::MAX_THUMBNAIL_BYTES) {
                    return new StoredMedia($content, 'image/jpeg');
                }
            }

            return null;
        } finally {
            imagedestroy($thumbnail);
            imagedestroy($source);
        }
    }
}
