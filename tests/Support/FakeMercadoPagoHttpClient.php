<?php

declare(strict_types=1);

namespace Korbytes\Payments\Tests\Support;

use MercadoPago\Exceptions\MPApiException;
use MercadoPago\Net\MPHttpClient;
use MercadoPago\Net\MPRequest;
use MercadoPago\Net\MPResponse;

/**
 * Test double for the MercadoPago SDK http client.
 *
 * Responses/failures are matched against the request URI (in registration
 * order) so tests can queue up canned results without hitting the real API.
 *
 * Note: MPResponse carries a status code, but only MPDefaultHttpClient (the
 * real curl-based implementation) inspects it to throw MPApiException. Since
 * this fake replaces the http client wholesale, error scenarios must be
 * registered via failWith() so an exception is actually thrown, matching
 * what MercadoPagoDriver's catch (\Exception) blocks expect.
 */
class FakeMercadoPagoHttpClient implements MPHttpClient
{
    /** @var array<int, array{uri: string, response?: MPResponse, exception?: \Exception}> */
    protected array $expectations = [];

    /** @var array<int, MPRequest> */
    public array $requests = [];

    public function respondTo(string $uriContains, int $statusCode, array $content): static
    {
        $this->expectations[] = [
            'uri' => $uriContains,
            'response' => new MPResponse($statusCode, $content),
        ];

        return $this;
    }

    public function failWith(string $uriContains, int $statusCode, array $content): static
    {
        $this->expectations[] = [
            'uri' => $uriContains,
            'exception' => new MPApiException('Api error. Check response for details', new MPResponse($statusCode, $content)),
        ];

        return $this;
    }

    public function send(MPRequest $request): MPResponse
    {
        $this->requests[] = $request;

        foreach ($this->expectations as $candidate) {
            if (str_contains($request->getUri(), $candidate['uri'])) {
                if (isset($candidate['exception'])) {
                    throw $candidate['exception'];
                }

                return $candidate['response'];
            }
        }

        throw new MPApiException('No fake response registered for '.$request->getUri(), new MPResponse(404, []));
    }
}
