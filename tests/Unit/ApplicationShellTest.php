<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Http\ApplicationShell;
use App\Http\Request;
use PHPUnit\Framework\TestCase;

final class ApplicationShellTest extends TestCase
{
    private string $directory;
    private string $shellPath;
    private string $html = "<!doctype html>\n<html lang=\"fr\"><body>Shell</body></html>\n";

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/rss-reader-shell-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0700));
        $this->shellPath = $this->directory . '/index.html';
        self::assertNotFalse(file_put_contents($this->shellPath, $this->html));
    }

    protected function tearDown(): void
    {
        if (is_file($this->shellPath)) {
            unlink($this->shellPath);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testGetAndHeadServeTheExactShellWithSecurityHeaders(): void
    {
        $shell = new ApplicationShell($this->shellPath);
        $get = $shell->handle(new Request('GET', '/'));
        $head = $shell->handle(new Request('HEAD', '/'));

        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertSame(200, $get->status);
        self::assertSame($this->html, $get->body);
        self::assertSame(200, $head->status);
        self::assertSame('', $head->body);
        self::assertSame($get->headers, $head->headers);
        self::assertSame([
            'Content-Type' => 'text/html; charset=utf-8',
            'Content-Length' => (string) strlen($this->html),
            'Cache-Control' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), geolocation=()',
            'Content-Security-Policy' => "default-src 'none'; script-src 'self'; style-src 'self'; img-src 'self' https: http:; font-src 'self'; connect-src 'self'; manifest-src 'self'; worker-src 'self'; base-uri 'none'; form-action 'self'; frame-src 'none'; frame-ancestors 'none'; object-src 'none'",
        ], $get->headers);
    }

    public function testQueryStringIsIgnored(): void
    {
        $response = (new ApplicationShell($this->shellPath))->handle(new Request(
            'GET',
            '/',
            query: ['ignored' => 'value'],
        ));

        self::assertNotNull($response);
        self::assertSame(200, $response->status);
        self::assertSame($this->html, $response->body);
    }

    public function testNonShellRequestsAreDelegated(): void
    {
        $shell = new ApplicationShell($this->shellPath);
        $requests = [
            new Request('POST', '/'),
            new Request('GET', '/api/categories'),
            new Request('GET', '/unknown'),
        ];

        foreach ($requests as $request) {
            self::assertNull($shell->handle($request));
        }
    }

    public function testUnreadableShellReturnsGenericServiceUnavailable(): void
    {
        $shell = new ApplicationShell($this->directory . '/missing.html');
        $get = $shell->handle(new Request('GET', '/'));
        $head = $shell->handle(new Request('HEAD', '/'));

        self::assertNotNull($get);
        self::assertNotNull($head);
        self::assertSame(503, $get->status);
        self::assertSame([
            'error' => [
                'code' => 'SERVICE_UNAVAILABLE',
                'message' => 'Service temporairement indisponible.',
            ],
        ], json_decode($get->body, true, 32, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString($this->directory, $get->body);
        self::assertSame(503, $head->status);
        self::assertSame('', $head->body);
        self::assertSame($get->headers, $head->headers);
    }
}
