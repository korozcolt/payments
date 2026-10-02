<?php

declare(strict_types=1);

use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\Core\Events\PaymentCreated;
use Korbytes\Payments\Core\Events\WebhookReceived;
use Korbytes\Payments\Core\Tests\Harness;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Drivers\WompiDriver;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Http\WebhookRequest;

require_once __DIR__.'/Support.php';

/*
 * Proof that a driver runs with NO framework: PSR-18 fake client, in-memory
 * repositories, array config and a PSR-14 recorder.
 */

function standaloneWompi(): array
{
    $harness = new Harness;
    $driver = (new WompiDriver($harness->context))->configure([
        'sandbox' => true,
        'public_key' => 'pub_test_xxx',
        'private_key' => 'prv_test_xxx',
        'integrity_secret' => 'test_integrity_xxx',
        'events_secret' => 'test_events_xxx',
    ]);

    return [$harness, $driver];
}

function wompiRequest(array $transaction, bool $valid = true): WebhookRequest
{
    $properties = ['id', 'status', 'amount_in_cents'];
    $hash = '';
    foreach ($properties as $p) {
        $hash .= $transaction[$p] ?? '';
    }

    return new WebhookRequest(payload: [
        'event' => 'transaction.updated',
        'data' => ['transaction' => $transaction],
        'signature' => [
            'properties' => $properties,
            'timestamp' => '1700000000',
            'checksum' => $valid ? hash('sha256', $hash.'1700000000test_events_xxx') : 'bad',
        ],
    ]);
}

it('charges and persists a transaction without any framework', function () {
    [$harness, $driver] = standaloneWompi();

    $result = $driver->charge(new PaymentData(referenceId: 'ORDER-1', amount: 50000));

    expect($result->success)->toBeTrue()
        ->and($result->transaction->getKey())->toBe(1)
        ->and($result->transaction->status)->toBe(PaymentStatus::Pending)
        ->and($result->signature)->not->toBeEmpty()
        ->and($harness->events->of(PaymentCreated::class))->toHaveCount(1);
});

it('approves the transaction from a webhook and emits PSR-14 events', function () {
    [$harness, $driver] = standaloneWompi();

    $charge = $driver->charge(new PaymentData(referenceId: 'ORDER-2', amount: 50000));
    $request = wompiRequest([
        'id' => 'wompi-1', 'status' => 'APPROVED', 'amount_in_cents' => 50000, 'reference' => $charge->reference,
    ]);

    expect($driver->verifyWebhookSignature($request))->toBeTrue();

    $result = $driver->processWebhook($request);

    expect($result->success)->toBeTrue()
        ->and($result->status)->toBe(PaymentStatus::Approved)
        ->and($harness->transactions->find(1)->status)->toBe(PaymentStatus::Approved)
        ->and($harness->transactions->find(1)->provider_transaction_id)->toBe('wompi-1')
        ->and($harness->events->of(WebhookReceived::class))->toHaveCount(1)
        ->and($harness->events->of(PaymentApproved::class))->toHaveCount(1);

    // A repeated webhook on a final transaction is idempotent.
    expect($driver->processWebhook($request)->errorCode)->toBe('DUPLICATE_WEBHOOK');
});

it('rejects a webhook with a bad signature', function () {
    [, $driver] = standaloneWompi();

    $driver->verifyWebhookSignature(wompiRequest(['id' => 'x', 'status' => 'APPROVED', 'amount_in_cents' => 1], valid: false));
})->throws(InvalidWebhookSignatureException::class);
