<?php

declare(strict_types=1);

namespace Korbytes\Payments\Slim\Tests;

use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\StreamInterface;
use Slim\Psr7\Factory\RequestFactory;
use Slim\Psr7\Factory\StreamFactory;

/** One object implementing both PSR-17 factories the core needs, built on slim/psr7. */
final class Psr17 implements RequestFactoryInterface, StreamFactoryInterface
{
    public function createRequest(string $method, $uri): RequestInterface
    {
        return (new RequestFactory)->createRequest($method, $uri);
    }

    public function createStream(string $content = ''): StreamInterface
    {
        return (new StreamFactory)->createStream($content);
    }

    public function createStreamFromFile(string $filename, string $mode = 'r'): StreamInterface
    {
        return (new StreamFactory)->createStreamFromFile($filename, $mode);
    }

    public function createStreamFromResource($resource): StreamInterface
    {
        return (new StreamFactory)->createStreamFromResource($resource);
    }
}
