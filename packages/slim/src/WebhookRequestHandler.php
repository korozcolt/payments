<?php

declare(strict_types=1);

namespace Korbytes\Payments\Slim;

use Korbytes\Payments\Core\WebhookHandler;
use Korbytes\Payments\Http\WebhookRequest;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * PSR-15 request handler for provider webhooks. The provider name is read from the
 * `provider` request attribute (Slim exposes route arguments as attributes), falling
 * back to the last path segment, so it also works in any other PSR-15 stack.
 *
 * Status codes and bodies come from the core's WebhookHandler and match the Laravel,
 * CodeIgniter and Symfony adapters.
 */
final class WebhookRequestHandler implements RequestHandlerInterface
{
    public function __construct(
        private readonly WebhookHandler $webhooks,
        private readonly ResponseFactoryInterface $responses,
    ) {}

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        $provider = (string) ($request->getAttribute('provider')
            ?? basename(rtrim($request->getUri()->getPath(), '/')));

        $result = $this->webhooks->handle(
            $provider,
            WebhookRequest::fromPsr7($request),
            ['ip' => $request->getServerParams()['REMOTE_ADDR'] ?? null],
        );

        $response = $this->responses->createResponse($result->status)
            ->withHeader('Content-Type', 'application/json');
        $response->getBody()->write(json_encode($result->body, JSON_THROW_ON_ERROR));

        return $response;
    }
}
