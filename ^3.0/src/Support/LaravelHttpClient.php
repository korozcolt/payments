<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Illuminate\Support\Facades\Http;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

/**
 * PSR-18 client over Laravel's Http facade.
 *
 * Going through the facade (rather than raw Guzzle) keeps Http::fake() and
 * Http::assertSent() working exactly as before the core extraction.
 * Network failures surface as Illuminate\Http\Client\ConnectionException.
 */
final class LaravelHttpClient implements ClientInterface
{
    public function __construct(private readonly int $timeout = 30) {}

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $headers = [];
        foreach ($request->getHeaders() as $name => $values) {
            $headers[$name] = implode(', ', $values);
        }

        $pending = Http::timeout($this->timeout)->withHeaders($headers);

        $body = (string) $request->getBody();
        if ($body !== '') {
            $pending = $pending->withBody($body, $request->getHeaderLine('Content-Type') ?: 'application/json');
        }

        return $pending
            ->send($request->getMethod(), (string) $request->getUri())
            ->toPsrResponse();
    }
}
