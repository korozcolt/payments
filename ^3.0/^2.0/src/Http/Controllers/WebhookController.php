<?php

declare(strict_types=1);

namespace Korbytes\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Korbytes\Payments\Core\WebhookHandler;

/**
 * Controller for handling payment provider webhooks.
 *
 * All behaviour (status codes, logging, signature handling) lives in the
 * framework-agnostic WebhookHandler so every adapter answers identically.
 */
class WebhookController extends Controller
{
    /**
     * Handle incoming webhook from a payment provider.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        $response = app(WebhookHandler::class)->handle($provider, $request, ['ip' => $request->ip()]);

        return response()->json($response->body, $response->status);
    }
}
