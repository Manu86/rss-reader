<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Security\CurrentUser;
use App\Service\FeedDiscoveryService;

final readonly class FeedDiscoveryController
{
    public function __construct(
        private FeedDiscoveryService $discovery,
        private CurrentUser $currentUser,
    ) {}

    public function discover(Request $request): Response
    {
        $user = $this->currentUser->require();
        $data = $request->json();
        $request->rejectUnknownFields($data, ['url']);
        if (!isset($data['url']) || !is_string($data['url']) || trim($data['url']) === '') {
            throw new ValidationException(['url' => 'L’URL du site ou du flux est requise.']);
        }

        return Response::json(['data' => $this->discovery->discover($user->id, $data['url'])]);
    }
}
