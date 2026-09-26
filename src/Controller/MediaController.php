<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\NotFoundException;
use App\Http\Response;
use App\Repository\ArticleRepository;
use App\Repository\FeedRepository;
use App\Security\CurrentUser;
use App\Storage\MediaStorage;

final readonly class MediaController
{
    public function __construct(
        private FeedRepository $feeds,
        private ArticleRepository $articles,
        private MediaStorage $storage,
        private CurrentUser $currentUser,
    ) {}

    public function favicon(int $feedId): Response
    {
        $user = $this->currentUser->require();
        $feed = $this->feeds->findOwned($feedId, $user->id);
        if ($feed === null || $feed->faviconPath === null) {
            throw new NotFoundException('Média introuvable.');
        }

        return $this->response($user->id, $feed->faviconPath);
    }

    public function articleImage(int $articleId): Response
    {
        $user = $this->currentUser->require();
        $path = $this->articles->findImagePathOwned($articleId, $user->id);
        if ($path === null) {
            throw new NotFoundException('Média introuvable.');
        }

        return $this->response($user->id, $path);
    }

    private function response(int $userId, string $path): Response
    {
        $media = $this->storage->read($userId, $path);
        if ($media === null) {
            throw new NotFoundException('Média introuvable.');
        }

        return new Response(200, $media->content, [
            'Content-Type' => $media->contentType,
            'Content-Length' => (string) strlen($media->content),
            'Content-Disposition' => 'inline',
        ]);
    }
}
