<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Psr\Http\Message\UriInterface;

/**
 * Minimal PSR-17 request/stream factory over guzzlehttp/psr7.
 *
 * Laravel (via illuminate/http) always ships guzzlehttp/psr7, but Laravel 10 may
 * resolve psr7 1.x, which has no HttpFactory class — hence this tiny shim.
 */
final class PsrFactory implements RequestFactoryInterface, StreamFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return new Request($method, $uri instanceof UriInterface ? (string) $uri : $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return Utils::streamFor($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return Utils::streamFor(Utils::tryFopen($filename, $mode));
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return Utils::streamFor($resource);
    }
}
