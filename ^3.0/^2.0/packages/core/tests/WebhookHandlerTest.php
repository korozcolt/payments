<?php

declare(strict_types=1);

use Korbytes\Payments\Core\PaymentManager;
use Korbytes\Payments\Core\Tests\Harness;
use Korbytes\Payments\Core\WebhookHandler;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Drivers\WompiDriver;
use Korbytes\Payments\Http\WebhookRequest;
use Psr\Log\NullLogger;

require_once __DIR__.'/Support.php';

function webhookHarness(): array
{
    $harness = new Harness(['drivers' => ['wompi' => [
        'sandbox' => true, 'public_key' => 'pub', 'private_key' => 'prv',
        'integrity_secret' => 'i', 'events_secret' => 'test_events_xxx',
    ]]]);
    $manager = new PaymentManager($harness->context);

    return [$harness, $manager, new WebhookHandler($manager, new NullLogger)];
}

function signedWompi(array $tx, bool $valid = true): WebhookRequest
{
    $hash = ($tx['id'] ?? '').($tx['status'] ?? '').($tx['amount_in_cents'] ?? '');

    return new WebhookRequest(payload: [
        'data' => ['transaction' => $tx],
        'signature' => [
            'properties' => ['id', 'status', 'amount_in_cents'],
            'timestamp' => '1',
            'checksum' => $valid ? hash('sha256', $hash.'1test_events_xxx') : 'bad',
        ],
    ]);
}

it('answers 400 for an unknown provider', function () {
    [, , $handler] = webhookHarness();

    $response = $handler->handle('nope', new WebhookRequest);

    expect($response->status)->toBe(400)
        ->and($response->body)->toBe(['success' => false, 'error' => 'Invalid payment provider']);
});

it('answers 400 when the provider is not configured', function () {
    [, , $handler] = webhookHarness();

    expect($handler->handle('epayco', new WebhookRequest)->status)->toBe(400);
});

it('answers 401 for a bad signature', function () {
    [, , $handler] = webhookHarness();

    $response = $handler->handle('wompi', signedWompi(['id' => 'a', 'status' => 'APPROVED', 'amount_in_cents' => 1], valid: false));

    expect($response->status)->toBe(401)
        ->and($response->body['error'])->toBe('Invalid webhook signature');
});

it('answers 200 with transaction id and status when processed', function () {
    [$harness, $manager, $handler] = webhookHarness();

    $charge = $manager->driver('wompi')->charge(new PaymentData(referenceId: 'O-1', amount: 50000));

    $response = $handler->handle('wompi', signedWompi([
        'id' => 'w-1', 'status' => 'APPROVED', 'amount_in_cents' => 50000, 'reference' => $charge->reference,
    ]));

    expect($response->status)->toBe(200)
        ->and($response->body)->toBe(['success' => true, 'transaction_id' => 1, 'status' => 'approved']);
});

it('answers 200 with success=false for a known failure so providers do not retry', function () {
    [, , $handler] = webhookHarness();

    $response = $handler->handle('wompi', signedWompi([
        'id' => 'w-2', 'status' => 'APPROVED', 'amount_in_cents' => 1, 'reference' => 'REF-X-TXN-999',
    ]));

    expect($response->status)->toBe(200)
        ->and($response->body['success'])->toBeFalse()
        ->and($response->body['error_code'])->toBe('TRANSACTION_NOT_FOUND');
});

it('answers 500 on an unexpected error', function () {
    [$harness, $manager, $handler] = webhookHarness();

    $manager->extend('wompi', ExplodingDriver::class);

    expect($handler->handle('wompi', new WebhookRequest)->status)->toBe(500);
});

final class ExplodingDriver extends WompiDriver
{
    public function verifyWebhookSignature(object $request): bool
    {
        throw new RuntimeException('boom');
    }
}
