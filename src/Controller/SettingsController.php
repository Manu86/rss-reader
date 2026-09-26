<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Security\CurrentUser;
use App\Service\UserSettingsService;

final readonly class SettingsController
{
    public function __construct(
        private UserSettingsService $settings,
        private CurrentUser $currentUser,
    ) {}

    public function show(): Response
    {
        $user = $this->currentUser->require();

        return Response::json(['data' => $this->settings->get($user->id)->publicData()]);
    }

    public function update(Request $request): Response
    {
        $user = $this->currentUser->require();
        $data = $request->json();
        $request->rejectUnknownFields($data, ['articles_per_page', 'theme']);
        $articlesPerPage = $data['articles_per_page'] ?? null;
        $theme = $data['theme'] ?? null;
        if ($articlesPerPage === null && $theme === null) {
            throw new ValidationException([
                'articles_per_page' => 'Une valeur à mettre à jour est requise.',
            ]);
        }
        if ($articlesPerPage !== null && !is_int($articlesPerPage)) {
            throw new ValidationException([
                'articles_per_page' => 'Un nombre d’articles par page est requis.',
            ]);
        }
        if ($theme !== null && !is_string($theme)) {
            throw new ValidationException([
                'theme' => 'Le thème doit être light ou dark.',
            ]);
        }

        return Response::json(['data' => $this->settings->update(
            $user->id,
            $articlesPerPage,
            $theme,
        )->publicData()]);
    }
}
