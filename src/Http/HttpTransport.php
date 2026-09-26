<?php

declare(strict_types=1);

namespace App\Http;

interface HttpTransport
{
    public function send(TransportRequest $request): TransportResponse;
}
