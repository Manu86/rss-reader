<?php

declare(strict_types=1);

use App\ApplicationFactory;
use App\Http\ApplicationShell;
use App\Http\Request;
use App\Http\Response;

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

try {
    $request = Request::fromGlobals();
    $response = (new ApplicationShell($root . '/public/index.html'))->handle($request);
    if ($response === null) {
        $response = ApplicationFactory::createWeb($root)->handle($request);
    }
    $response->emit();
} catch (Throwable) {
    error_log('Application bootstrap failure');
    Response::json([
        'error' => [
            'code' => 'SERVICE_UNAVAILABLE',
            'message' => 'Service temporairement indisponible.',
        ],
    ], 503)->withHeaders([
        'Cache-Control' => 'no-store',
        'X-Content-Type-Options' => 'nosniff',
    ])->emit();
}
