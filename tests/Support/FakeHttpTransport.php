<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Exception\RemoteHttpException;
use App\Http\HttpTransport;
use App\Http\TransportRequest;
use App\Http\TransportResponse;

final class FakeHttpTransport implements HttpTransport
{
    /** @var list<TransportRequest> */
    public array $requests = [];

    /** @param list<TransportResponse> $responses */
    public function __construct(private array $responses) {}

    public function send(TransportRequest $request): TransportResponse
    {
        $this->requests[] = $request;
        $response = array_shift($this->responses);
        if (!$response instanceof TransportResponse) {
            throw new RemoteHttpException('TEST_TRANSPORT_EMPTY', 'Aucune réponse de test disponible.');
        }

        return $response;
    }
}
