<?php

declare(strict_types=1);

namespace App\Http;

final readonly class ApplicationShell
{
    private const CONTENT_SECURITY_POLICY = "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' https: http:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; form-action 'self'; frame-src 'none'; frame-ancestors 'none'; object-src 'none'";
    private const PERMISSIONS_POLICY = 'camera=(), microphone=(), geolocation=()';

    public function __construct(private string $path) {}

    public function handle(Request $request): ?Response
    {
        if ($request->path !== '/' || !in_array($request->method, ['GET', 'HEAD'], true)) {
            return null;
        }

        $content = @file_get_contents($this->path);
        if (!is_string($content)) {
            return $this->unavailable($request->method === 'HEAD');
        }

        return new Response(
            200,
            $request->method === 'HEAD' ? '' : $content,
            [
                'Content-Type' => 'text/html; charset=utf-8',
                'Content-Length' => (string) strlen($content),
                'Cache-Control' => 'no-cache',
                'X-Content-Type-Options' => 'nosniff',
                'Referrer-Policy' => 'same-origin',
                'Permissions-Policy' => self::PERMISSIONS_POLICY,
                'Content-Security-Policy' => self::CONTENT_SECURITY_POLICY,
            ],
        );
    }

    private function unavailable(bool $withoutBody): Response
    {
        $response = Response::json([
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Service temporairement indisponible.',
            ],
        ], 503)->withHeaders([
            'Cache-Control' => 'no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);

        if (!$withoutBody) {
            return $response;
        }

        return new Response($response->status, '', $response->headers);
    }
}
