<?php

declare(strict_types=1);

namespace Korbytes\Payments\Http;

use Psr\Http\Message\ResponseInterface;

/**
 * Thin wrapper over a PSR-7 response, exposing the small surface drivers need.
 */
final class Response
{
    public function __construct(private readonly ResponseInterface $response) {}

    public function status(): int
    {
        return $this->response->getStatusCode();
    }

    public function body(): string
    {
        $stream = $this->response->getBody();

        if ($stream->isSeekable()) {
            $stream->rewind();
        }

        return (string) $stream;
    }

    /**
     * Decoded JSON body, or null when the body is empty / not valid JSON.
     *
     * @return array<mixed>|null
     */
    public function json(): ?array
    {
        $decoded = json_decode($this->body(), true);

        return is_array($decoded) ? $decoded : null;
    }

    public function header(string $name): ?string
    {
        return $this->response->hasHeader($name) ? $this->response->getHeaderLine($name) : null;
    }

    public function successful(): bool
    {
        return $this->status() >= 200 && $this->status() < 300;
    }

    public function failed(): bool
    {
        return $this->status() >= 400;
    }
}
