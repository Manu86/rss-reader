<?php

declare(strict_types=1);

namespace App\Controller;

use App\Exception\ValidationException;
use App\Http\Request;
use App\Http\Response;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Security\Session;
use App\Service\AuthenticationService;
use App\Service\UserService;

final readonly class AuthController
{
    public function __construct(
        private AuthenticationService $authentication,
        private UserService $users,
        private Session $session,
        private CsrfTokenManager $csrf,
        private CurrentUser $currentUser,
    ) {}

    public function csrf(): Response
    {
        return Response::json(['data' => ['csrf_token' => $this->csrf->token()]]);
    }

    public function login(Request $request): Response
    {
        $data = $request->json();
        $request->rejectUnknownFields($data, ['username', 'password']);
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        $fields = [];
        if (!is_string($username) || trim($username) === '') {
            $fields['username'] = 'Le nom d’utilisateur est requis.';
        }
        if (!is_string($password) || $password === '') {
            $fields['password'] = 'Le mot de passe est requis.';
        }
        if ($fields !== []) {
            throw new ValidationException($fields);
        }

        $user = $this->authentication->login($username, $password, $request->remoteAddress);
        $this->session->regenerate();
        $this->currentUser->set($user);
        $token = $this->csrf->rotate();

        return Response::json(['data' => [
            'user' => $user->publicData(),
            'csrf_token' => $token,
        ]]);
    }

    public function logout(): Response
    {
        $this->session->invalidate();

        return Response::empty();
    }

    public function me(): Response
    {
        return Response::json(['data' => $this->currentUser->require()->publicData()]);
    }

    public function changePassword(Request $request): Response
    {
        $user = $this->currentUser->require();
        $data = $request->json();
        $request->rejectUnknownFields($data, ['current_password', 'new_password']);
        $currentPassword = $data['current_password'] ?? null;
        $newPassword = $data['new_password'] ?? null;
        $fields = [];
        if (!is_string($currentPassword) || $currentPassword === '') {
            $fields['current_password'] = 'Le mot de passe actuel est requis.';
        }
        if (!is_string($newPassword) || $newPassword === '') {
            $fields['new_password'] = 'Le nouveau mot de passe est requis.';
        }
        if ($fields !== []) {
            throw new ValidationException($fields);
        }

        $this->users->changeOwnPassword($user, $currentPassword, $newPassword);
        $this->session->regenerate();
        $token = $this->csrf->rotate();

        return Response::json(['data' => ['csrf_token' => $token]]);
    }
}
