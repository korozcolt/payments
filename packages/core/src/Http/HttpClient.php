<?php

declare(strict_types=1);

namespace Korbytes\Payments\Http;

use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;

/**
 * Small fluent HTTP helper built on PSR-18/PSR-17.
 *
 * POST/PUT/PATCH/DELETE send `$data` as a JSON body; GET appends it as a query string.
 * Network failures surface as the underlying PSR-18 client exceptions.
 *
 * PSR-18 has no timeout concept: configure it on the injected client (the Laravel
 * adapter uses 30 seconds, matching the pre-core behaviour).
 */
final class HttpClient
{
    /** @var array<string, string> */
    private array $headers = [];

    public function __construct(
        private readonly ClientInterface $client,
        private readonly RequestFactoryInterface $requestFactory,
        private readonly StreamFactoryInterface $streamFactory,
    ) {}

    /**
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        $clone = clone $this;
        $clone->headers = array_merge($this->headers, $headers);

        return $clone;
    }

    /**
     * No-op kept for call-site compatibility; see class docblock.
     */
    public function timeout(int $seconds): self
    {
        return $this;
    }

    /**
     * @param  array<mixed>  $query
     */
    public function get(string $url, array $query = []): Response
    {
        return $this->send('GET', $url, $query, null);
    }

    /**
     * @param  array<mixed>  $data
     */
    public function post(string $url, array $data = []): Response
    {
        return $this->send('POST', $url, [], $data);
    }

    /**
     * @param  array<mixed>  $data
     */
    public function put(string $url, array $data = []): Response
    {
        return $this->send('PUT', $url, [], $data);
    }

    /**
     * @param  array<mixed>  $data
     */
    public function patch(string $url, array $data = []): Response
    {
        return $this->send('PATCH', $url, [], $data);
    }

    /**
     * @param  array<mixed>  $data
     */
    public function delete(string $url, array $data = []): Response
    {
        return $this->send('DELETE', $url, [], $data);
    }

    /**
     * @param  array<mixed>  $query
     * @param  array<mixed>|null  $data
     */
    private function send(string $method, string $url, array $query, ?array $data): Response
    {
        if ($query !== []) {
            $url .= (str_contains($url, '?') ? '&' : '?').http_build_query($query);
        }

        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($this->headers as $name => $value) {
            $request = $request->withHeader($name, $value);
        }

        if ($data !== null) {
            $request = $request
                ->withHeader('Content-Type', 'application/json')
                ->withBody($this->streamFactory->createStream(json_encode($data, JSON_THROW_ON_ERROR)));
        }

        return new Response($this->client->sendRequest($request));
    }
}
