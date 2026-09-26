<?php

declare(strict_types=1);

namespace App\Http;

use App\Exception\RemoteHttpException;
use CurlHandle;

final class CurlHttpTransport implements HttpTransport
{
    public function send(TransportRequest $request): TransportResponse
    {
        $handle = curl_init($request->url);
        if (!$handle instanceof CurlHandle) {
            throw new RemoteHttpException('TRANSPORT_FAILED', 'Impossible d’initialiser la requête distante.');
        }

        $body = '';
        $tooLarge = false;
        $responseHeaders = [];
        $formattedHeaders = [];
        foreach ($request->headers as $name => $value) {
            $formattedHeaders[] = $name . ': ' . $value;
        }
        $address = str_contains($request->address, ':')
            ? '[' . $request->address . ']'
            : $request->address;
        $userAgent = $request->userAgent !== '' ? $request->userAgent : 'RSSReader/1.0';

        curl_setopt_array($handle, [
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
            CURLOPT_CONNECTTIMEOUT_MS => $request->connectTimeoutMs,
            CURLOPT_TIMEOUT_MS => $request->timeoutMs,
            CURLOPT_NOSIGNAL => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '',
            CURLOPT_NOPROXY => '*',
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => $formattedHeaders,
            CURLOPT_ENCODING => '',
            CURLOPT_RESOLVE => [sprintf('%s:%d:%s', $request->host, $request->port, $address)],
            CURLOPT_WRITEFUNCTION => static function (CurlHandle $curl, string $chunk) use (
                &$body,
                &$tooLarge,
                $request,
            ): int {
                unset($curl);
                if (strlen($body) + strlen($chunk) > $request->maxResponseBytes) {
                    $tooLarge = true;

                    return 0;
                }
                $body .= $chunk;

                return strlen($chunk);
            },
            CURLOPT_HEADERFUNCTION => static function (CurlHandle $curl, string $line) use (&$responseHeaders): int {
                unset($curl);
                $length = strlen($line);
                $trimmed = trim($line);
                if (str_starts_with($trimmed, 'HTTP/')) {
                    $responseHeaders = [];

                    return $length;
                }
                if ($trimmed === '' || !str_contains($trimmed, ':')) {
                    return $length;
                }
                [$name, $value] = explode(':', $trimmed, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);

                return $length;
            },
        ]);

        try {
            $success = curl_exec($handle);
            if ($success === false) {
                if ($tooLarge) {
                    throw new RemoteHttpException('RESPONSE_TOO_LARGE', 'La réponse distante dépasse la taille autorisée.');
                }
                throw new RemoteHttpException(
                    'TRANSPORT_FAILED',
                    sprintf('La requête distante a échoué (cURL %d).', curl_errno($handle)),
                );
            }
            $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
            if (!is_int($status) || $status < 100 || $status > 599) {
                throw new RemoteHttpException('INVALID_RESPONSE', 'La réponse HTTP distante est invalide.');
            }

            return new TransportResponse($status, $responseHeaders, $body);
        } finally {
            curl_close($handle);
        }
    }
}
