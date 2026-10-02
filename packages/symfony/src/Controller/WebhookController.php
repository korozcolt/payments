<?php

declare(strict_types=1);

namespace Korbytes\Payments\Symfony\Controller;

use Korbytes\Payments\Core\Standalone;
use Korbytes\Payments\Symfony\RequestMapper;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * POST /payments/webhooks/{provider}. Answers exactly like the Laravel,
 * CodeIgniter and Slim adapters (400 / 401 / 200 / 500) via the core's WebhookHandler.
 */
final class WebhookController
{
    public function __construct(private readonly Standalone $payments) {}

    public function __invoke(Request $request, string $provider): JsonResponse
    {
        $result = $this->payments->webhooks()->handle(
            $provider,
            RequestMapper::fromSymfony($request),
            ['ip' => $request->getClientIp()],
        );

        return new JsonResponse($result->body, $result->status);
    }
}
