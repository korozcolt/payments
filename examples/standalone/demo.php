<?php

declare(strict_types=1);

use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Http\WebhookRequest;

/*
 * Offline end-to-end demo: charge -> signed Wompi webhook -> approved in the database.
 *
 *   composer install && php demo.php
 */
$payments = require __DIR__.'/bootstrap.php';

$payments->events()->listen(PaymentApproved::class, function (PaymentApproved $event) {
    echo "  event PaymentApproved for order {$event->transaction->reference_id}\n";
});

// 1. Create the payment: gives you the data for Wompi's checkout widget.
$charge = $payments->driver('wompi')->charge(new PaymentData(
    referenceId: 'ORDER-'.date('His'),
    amount: 5000000, // in cents: COP $50.000
    customer: ['name' => 'Ana Pérez', 'email' => 'ana@example.com'],
));

echo "Charge created\n  reference: {$charge->reference}\n  widget:    {$charge->widgetUrl}\n";

// 2. Wompi later calls your webhook. Here we fabricate that call with a valid signature.
$transaction = ['id' => 'wompi-demo-1', 'status' => 'APPROVED', 'amount_in_cents' => 5000000, 'reference' => $charge->reference];
$properties = ['id', 'status', 'amount_in_cents'];
$timestamp = (string) time();
$checksum = hash('sha256', 'wompi-demo-1'.'APPROVED'.'5000000'.$timestamp.'test_events_xxx');

$response = $payments->webhooks()->handle('wompi', new WebhookRequest(payload: [
    'event' => 'transaction.updated',
    'data' => ['transaction' => $transaction],
    'signature' => ['properties' => $properties, 'timestamp' => $timestamp, 'checksum' => $checksum],
]));

echo "Webhook answered HTTP {$response->status}: ".json_encode($response->body)."\n";
