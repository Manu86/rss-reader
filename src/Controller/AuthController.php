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
use App\Service\RememberTokenService;
use App\Service\UserService;

final readonly class AuthController
{
    public function __construct(
        private AuthenticationService $authentication,
        private UserService $users,
        private Session $session,
        private CsrfTokenManager $csrf,
        private CurrentUser $currentUser,
        private RememberTokenService $rememberTokens,
    ) {}

    public function csrf(): Response
    {
        return Response::json(['data' => ['csrf_token' => $this->csrf->token()]]);
    }

    public function login(Request $request): Response
    {
        $data = $request->json();
        $request->rejectUnknownFields($data, ['username', 'password', 'remember']);
        $username = $data['username'] ?? null;
        $password = $data['password'] ?? null;
        $remember = $data['remember'] ?? false;
        $fields = [];
        if (!is_string($username) || trim($username) === '') {
            $fields['username'] = 'Le nom d’utilisateur est requis.';
        }
        if (!is_string($password) || $password === '') {
            $fields['password'] = 'Le mot de passe est requis.';
        }
        if (!is_bool($remember)) {
            $fields['remember'] = 'La valeur attendue est un booléen.';
        }
        if ($fields !== []) {
            throw new ValidationException($fields);
        }

        $user = $this->authentication->login($username, $password, $request->remoteAddress);
        $this->session->regenerate();
        $this->currentUser->set($user);
        $token = $this->csrf->rotate();

        // The cookie is always written: either a fresh token, or an explicit
        // deletion so a previous "remember me" cookie cannot silently sign the
        // user back in after a deliberate uncheck. Unchecking also drops the
        // stored row, not just the browser copy.
        if ($remember) {
            $cookie = $this->rememberTokens->issue($user->id);
        } else {
            $this->rememberTokens->revoke($request->cookie($this->rememberTokens->cookieName()));
            $cookie = $this->rememberTokens->clearCookie();
        }

        return Response::json(['data' => [
            'user' => $user->publicData(),
            'csrf_token' => $token,
        ]])->withHeaders(['Set-Cookie' => $cookie->toHeader()]);
    }

    public function logout(Request $request): Response
    {
        $this->rememberTokens->revoke($request->cookie($this->rememberTokens->cookieName()));
        $this->session->invalidate();

        return Response::empty()->withHeaders([
            'Set-Cookie' => $this->rememberTokens->clearCookie()->toHeader(),
        ]);
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
        $this->rememberTokens->revokeAll($user->id);
        $this->session->regenerate();
        $token = $this->csrf->rotate();

        // A password change is an explicit re-secure: remembered devices are
        // forgotten, so every other browser must authenticate again.
        return Response::json(['data' => ['csrf_token' => $token]])->withHeaders([
            'Set-Cookie' => $this->rememberTokens->clearCookie()->toHeader(),
        ]);
    }

    public function updateProfile(Request $request): Response
    {
        $user = $this->currentUser->require();
        $data = $request->json();
        $request->rejectUnknownFields($data, ['email', 'recommendation_email_frequency']);
        if (!array_key_exists('email', $data) || !is_string($data['email'])) {
            throw new ValidationException([
                'email' => 'Une adresse email est requise.',
            ]);
        }
        $frequency = $data['recommendation_email_frequency'] ?? null;
        if (!is_string($frequency)) {
            throw new ValidationException([
                'recommendation_email_frequency' => 'Une fréquence d’envoi est requise.',
            ]);
        }

        return Response::json([
            'data' => [
                ...$this->users->updateOwnProfile($user, $data['email'], $frequency)->publicData(),
                'recommendation_email_frequency' => $frequency,
            ],
        ]);
    }
}
