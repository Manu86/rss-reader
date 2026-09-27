<?php

declare(strict_types=1);

namespace App\Http;

use App\Controller\ArticleController;
use App\Controller\AuthController;
use App\Controller\CategoryController;
use App\Controller\FeedController;
use App\Controller\FeedDiscoveryController;
use App\Controller\MediaController;
use App\Controller\OpmlController;
use App\Controller\RecommendationController;
use App\Controller\SettingsController;
use App\Exception\ApiException;
use App\Security\CsrfTokenManager;
use App\Security\CurrentUser;
use App\Service\RememberTokenService;
use Throwable;

final readonly class ApiKernel
{
    public function __construct(
        private AuthController $auth,
        private CategoryController $categories,
        private ArticleController $articles,
        private RecommendationController $recommendations,
        private FeedController $feeds,
        private FeedDiscoveryController $feedDiscovery,
        private MediaController $media,
        private SettingsController $settings,
        private OpmlController $opml,
        private CsrfTokenManager $csrf,
        private CurrentUser $currentUser,
        private RememberTokenService $rememberTokens,
    ) {}

    public function handle(Request $request): Response
    {
        try {
            $this->restoreRememberedSession($request);
            if (in_array($request->method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)) {
                $this->csrf->validate($request->header('x-csrf-token'));
            }

            $response = $this->dispatch($request);
        } catch (ApiException $exception) {
            $error = [
                'code' => $exception->errorCode,
                'message' => $exception->getMessage(),
            ];
            if ($exception->fields !== []) {
                $error['fields'] = $exception->fields;
            }
            $response = Response::json(['error' => $error], $exception->status);
        } catch (Throwable) {
            $incident = bin2hex(random_bytes(8));
            error_log(sprintf('Unexpected API failure [%s]', $incident));
            $response = Response::json([
                'error' => [
                    'code' => 'INTERNAL_ERROR',
                    'message' => 'Une erreur interne est survenue.',
                ],
            ], 500);
        }

        return $response->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "default-src 'none'; frame-ancestors 'none'; base-uri 'none'",
        ]);
    }

    /**
     * Reopens a session from a valid "remember me" cookie, so an expired PHP
     * session does not force a new password entry. Login and logout are
     * excluded: the first must not silently reuse a previous device, the second
     * must close the remembered state rather than restore it.
     */
    private function restoreRememberedSession(Request $request): void
    {
        if ($request->method === 'POST'
            && ($request->path === '/api/auth/login' || $request->path === '/api/auth/logout')) {
            return;
        }
        if ($this->currentUser->isAuthenticated()) {
            return;
        }

        $userId = $this->rememberTokens->resolve(
            $request->cookie($this->rememberTokens->cookieName()),
        );
        if ($userId !== null) {
            $this->currentUser->restore($userId);
        }
    }

    private function dispatch(Request $request): Response
    {
        $exact = match ($request->method . ' ' . $request->path) {
            'GET /api/auth/csrf' => $this->auth->csrf(),
            'POST /api/auth/login' => $this->auth->login($request),
            'POST /api/auth/logout' => $this->auth->logout($request),
            'GET /api/auth/me' => $this->auth->me(),
            'POST /api/settings/password' => $this->auth->changePassword($request),
            'GET /api/settings' => $this->settings->show(),
            'PATCH /api/settings' => $this->settings->update($request),
            'POST /api/opml/import' => $this->opml->import($request),
            'GET /api/opml/export' => $this->opml->export(),
            'GET /api/categories' => $this->categories->index(),
            'POST /api/categories' => $this->categories->create($request),
            'GET /api/articles' => $this->articles->index($request),
            'GET /api/search' => $this->articles->search($request),
            'GET /api/recommendations' => $this->recommendations->index($request),
            'GET /api/counts' => $this->articles->counts(),
            'GET /api/feeds' => $this->feeds->index($request),
            'POST /api/feeds' => $this->feeds->create($request),
            'POST /api/feeds/refresh' => $this->feeds->refreshAll(),
            'POST /api/feed-discovery' => $this->feedDiscovery->discover($request),
            default => null,
        };
        if ($exact !== null) {
            return $exact;
        }

        if (preg_match('#\A/api/categories/([1-9][0-9]*)\z#', $request->path, $matches) === 1) {
            $categoryId = (int) $matches[1];

            return match ($request->method) {
                'PATCH' => $this->categories->update($request, $categoryId),
                'DELETE' => $this->categories->delete($categoryId),
                default => $this->notFound(),
            };
        }

        if (preg_match('#\A/api/feeds/([1-9][0-9]*)\z#', $request->path, $matches) === 1) {
            $feedId = (int) $matches[1];

            return match ($request->method) {
                'GET' => $this->feeds->show($feedId),
                'PATCH' => $this->feeds->update($request, $feedId),
                'DELETE' => $this->feeds->delete($feedId),
                default => $this->notFound(),
            };
        }

        if (preg_match('#\A/api/articles/([1-9][0-9]*)\z#', $request->path, $matches) === 1) {
            $articleId = (int) $matches[1];

            return match ($request->method) {
                'GET' => $this->articles->show($articleId),
                'PATCH' => $this->articles->update($request, $articleId),
                default => $this->notFound(),
            };
        }

        if (preg_match('#\A/api/feeds/([1-9][0-9]*)/refresh\z#', $request->path, $matches) === 1
            && $request->method === 'POST') {
            return $this->feeds->refresh((int) $matches[1]);
        }

        if (preg_match('#\A/api/feeds/([1-9][0-9]*)/favicon\z#', $request->path, $matches) === 1
            && $request->method === 'GET') {
            return $this->media->favicon((int) $matches[1]);
        }

        if (preg_match('#\A/api/articles/([1-9][0-9]*)/image\z#', $request->path, $matches) === 1
            && $request->method === 'GET') {
            return $this->media->articleImage((int) $matches[1]);
        }

        return $this->notFound();
    }

    private function notFound(): Response
    {
        return Response::json([
            'error' => [
                'code' => 'NOT_FOUND',
                'message' => 'Ressource introuvable.',
            ],
        ], 404);
    }
}
