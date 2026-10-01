<?php

declare(strict_types=1);

use Korbytes\Payments\Http\WebhookRequest;

/*
 * Webhook endpoint, e.g. POST /webhook.php?provider=wompi
 * Point the provider's dashboard at this URL. The status codes (400/401/200/500)
 * are exactly the ones the Laravel package answers with.
 */
$payments = require __DIR__.'/bootstrap.php';

$response = $payments->webhooks()->handle(
    provider: $_GET['provider'] ?? '',
    request: WebhookRequest::fromGlobals(),
    logContext: ['ip' => $_SERVER['REMOTE_ADDR'] ?? null],
);

http_response_code($response->status);
header('Content-Type: application/json');
echo json_encode($response->body);
