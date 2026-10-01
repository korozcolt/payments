<?php

use Illuminate\Support\Facades\Event;
use Korbytes\Payments\Contracts\PaymentDriverInterface;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentCreated;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Events\WebhookReceived;
use Korbytes\Payments\Facades\Payments;

/*
 * Characterization tests (#16, Phase 0): pin the HTTP contract of the webhook
 * endpoint and the event payloads BEFORE the core is extracted, so the Laravel
 * adapter can be proven backward compatible. Do not edit these to make a
 * refactor pass.
 */

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);
});

function wompiWebhookBody(array $transactionData, bool $validSignature = true): array
{
    $properties = ['id', 'status', 'amount_in_cents'];
    $timestamp = '1700000000';
    $stringToHash = '';

    foreach ($properties as $property) {
        $stringToHash .= data_get($transactionData, $property, '');
    }

    $checksum = hash('sha256', $stringToHash.$timestamp.'test_events_xxx');

    return [
        'event' => 'transaction.updated',
        'data' => ['transaction' => $transactionData],
        'signature' => [
            'properties' => $properties,
            'timestamp' => $timestamp,
            'checksum' => $validSignature ? $checksum : 'bad-checksum',
        ],
    ];
}

it('returns 400 for an unknown provider', function () {
    $this->postJson('/payments/webhooks/not-a-provider', [])
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'error' => 'Invalid payment provider']);
});

it('returns 400 when the provider is not enabled', function () {
    config(['payments.enabled' => ['wompi']]);

    $this->postJson('/payments/webhooks/epayco', [])
        ->assertStatus(400)
        ->assertExactJson(['success' => false, 'error' => 'Payment provider not available']);
});

it('returns 401 when the webhook signature is invalid', function () {
    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-BADSIG', amount: 50000));

    $body = wompiWebhookBody([
        'id' => 'wompi-ctrl-1',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
        'reference' => $charge->reference,
    ], validSignature: false);

    $this->postJson('/payments/webhooks/wompi', $body)
        ->assertStatus(401)
        ->assertExactJson(['success' => false, 'error' => 'Invalid webhook signature']);

    expect($charge->transaction->fresh()->status)->toBe(PaymentStatus::Pending);
});

it('returns 401 when the webhook has no signature block', function () {
    $this->postJson('/payments/webhooks/wompi', ['event' => 'transaction.updated', 'data' => []])
        ->assertStatus(401);
});

it('returns 200 with transaction id and status for a valid approved webhook', function () {
    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-CTRL-OK', amount: 50000));

    $body = wompiWebhookBody([
        'id' => 'wompi-ctrl-2',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
        'reference' => $charge->reference,
    ]);

    $this->postJson('/payments/webhooks/wompi', $body)
        ->assertOk()
        ->assertExactJson([
            'success' => true,
            'transaction_id' => $charge->transaction->id,
            'status' => 'approved',
        ]);

    expect($charge->transaction->fresh()->status)->toBe(PaymentStatus::Approved);
});

it('returns 200 with success=false for a known failure so the provider does not retry', function () {
    $body = wompiWebhookBody([
        'id' => 'wompi-ctrl-3',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
        'reference' => 'REF-DOES-NOT-EXIST-TXN-999999',
    ]);

    $this->postJson('/payments/webhooks/wompi', $body)
        ->assertStatus(200)
        ->assertJson(['success' => false, 'error_code' => 'TRANSACTION_NOT_FOUND']);
});

it('returns 500 on an unexpected driver error', function () {
    $driver = Mockery::mock(PaymentDriverInterface::class);
    $driver->shouldReceive('verifyWebhookSignature')->andThrow(new RuntimeException('boom'));

    Payments::shouldReceive('isAvailable')->with('wompi')->andReturn(true);
    Payments::shouldReceive('driver')->with('wompi')->andReturn($driver);

    $this->postJson('/payments/webhooks/wompi', [])
        ->assertStatus(500)
        ->assertExactJson(['success' => false, 'error' => 'Internal server error']);
});

it('dispatches PaymentCreated with the transaction and result on charge', function () {
    Event::fake([PaymentCreated::class]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-EVT-1', amount: 50000));

    Event::assertDispatched(PaymentCreated::class, function (PaymentCreated $event) use ($charge) {
        return $event->transaction->is($charge->transaction)
            && $event->result->success === true;
    });
});

it('dispatches WebhookReceived and PaymentApproved with the expected payload on approval', function () {
    Event::fake([WebhookReceived::class, PaymentApproved::class, PaymentRejected::class]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-EVT-2', amount: 50000));

    $body = wompiWebhookBody([
        'id' => 'wompi-evt-2',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
        'reference' => $charge->reference,
    ]);

    $this->postJson('/payments/webhooks/wompi', $body)->assertOk();

    Event::assertDispatched(WebhookReceived::class, function (WebhookReceived $event) use ($body) {
        return $event->provider === PaymentProvider::Wompi
            && $event->result->success === true
            && $event->rawPayload === $body;
    });

    Event::assertDispatched(PaymentApproved::class, function (PaymentApproved $event) use ($charge) {
        return $event->transaction->id === $charge->transaction->id
            && $event->transaction->status === PaymentStatus::Approved
            && $event->webhookResult->status === PaymentStatus::Approved
            && $event->webhookResult->providerTransactionId === 'wompi-evt-2';
    });

    Event::assertNotDispatched(PaymentRejected::class);
});
