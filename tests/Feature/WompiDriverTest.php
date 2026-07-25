<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentRefunded;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\PaymentTransaction;

beforeEach(function () {
    // Run migrations for tests
    $this->artisan('migrate', ['--database' => 'testing']);
});

/**
 * Mirrors WompiDriver::verifyWebhookSignature's checksum algorithm: it reads
 * $data['data']['transaction'] and resolves each `properties` entry against
 * that sub-array directly (not against the full event payload).
 */
function makeWompiWebhookChecksum(array $properties, array $transactionData, string $timestamp, string $secret): string
{
    $stringToHash = '';

    foreach ($properties as $property) {
        $stringToHash .= data_get($transactionData, $property, '');
    }

    $stringToHash .= $timestamp;
    $stringToHash .= $secret;

    return hash('sha256', $stringToHash);
}

function makeWompiWebhookPayload(array $transactionData, ?string $checksum = null, array $properties = ['id', 'status', 'amount_in_cents'], string $timestamp = '1700000000'): array
{
    return [
        'event' => 'transaction.updated',
        'data' => ['transaction' => $transactionData],
        'signature' => [
            'properties' => $properties,
            'timestamp' => $timestamp,
            'checksum' => $checksum ?? makeWompiWebhookChecksum($properties, $transactionData, $timestamp, 'test_events_xxx'),
        ],
    ];
}

it('creates a payment intent with wompi', function () {
    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER-123',
        amount: 50000,
        currency: 'COP',
        customer: [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ],
        returnUrl: 'https://example.com/return',
    );

    $result = Payments::driver('wompi')->charge($paymentData);

    expect($result->success)->toBeTrue();
    expect($result->transaction)->toBeInstanceOf(PaymentTransaction::class);
    expect($result->provider->value)->toBe('wompi');
    expect($result->amountInCents)->toBe(50000);
    expect($result->currency)->toBe('COP');
    expect($result->signature)->not->toBeEmpty();
    expect($result->widgetUrl)->toContain('wompi');
});

it('creates transaction record in database', function () {
    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER-456',
        amount: 100000,
        currency: 'COP',
        customer: [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ],
    );

    $result = Payments::driver('wompi')->charge($paymentData);

    $transaction = PaymentTransaction::where('reference_id', 'TEST-ORDER-456')->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(100000);
    expect($transaction->currency)->toBe('COP');
    expect($transaction->status)->toBe(PaymentStatus::Pending);
    expect($transaction->provider->value)->toBe('wompi');
});

it('generates correct reference format', function () {
    $paymentData = new PaymentData(
        referenceId: 'MY-ORDER-789',
        amount: 50000,
    );

    $result = Payments::driver('wompi')->charge($paymentData);

    expect($result->reference)->toMatch('/^REF-MY-ORDER-789-TXN-\d+$/');
});

it('includes customer data in extra', function () {
    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER',
        amount: 50000,
        customer: [
            'name' => 'Juan Pérez',
            'email' => 'juan@test.com',
            'phone' => '+573001234567',
        ],
    );

    $result = Payments::driver('wompi')->charge($paymentData);

    expect($result->extra['customer_name'])->toBe('Juan Pérez');
    expect($result->extra['customer_email'])->toBe('juan@test.com');
    expect($result->extra['customer_phone'])->toBe('+573001234567');
});

// verifyWebhookSignature()

it('verifies a valid wompi webhook signature', function () {
    $payload = makeWompiWebhookPayload([
        'id' => 'txn-1',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
    ]);

    $request = Request::create('/webhook/wompi', 'POST', $payload);

    expect(Payments::driver('wompi')->verifyWebhookSignature($request))->toBeTrue();
});

it('rejects an invalid wompi webhook signature', function () {
    $payload = makeWompiWebhookPayload([
        'id' => 'txn-1',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
    ], checksum: 'invalid-checksum');

    $request = Request::create('/webhook/wompi', 'POST', $payload);

    expect(fn () => Payments::driver('wompi')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('throws when signature block is missing from wompi webhook', function () {
    $request = Request::create('/webhook/wompi', 'POST', [
        'event' => 'transaction.updated',
        'data' => ['transaction' => ['id' => 'txn-1', 'status' => 'APPROVED']],
    ]);

    expect(fn () => Payments::driver('wompi')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class, 'Missing signature');
});

it('throws when wompi signature data is incomplete', function () {
    $request = Request::create('/webhook/wompi', 'POST', [
        'event' => 'transaction.updated',
        'data' => ['transaction' => ['id' => 'txn-1', 'status' => 'APPROVED']],
        'signature' => ['properties' => ['id']], // missing timestamp/checksum
    ]);

    expect(fn () => Payments::driver('wompi')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class, 'Incomplete signature data');
});

// processWebhook()

it('approves a transaction on wompi webhook with status APPROVED', function () {
    Event::fake([PaymentApproved::class]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-APPROVE', amount: 50000));

    $payload = makeWompiWebhookPayload([
        'id' => 'wompi-txn-1',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
        'reference' => $charge->reference,
    ]);

    $request = Request::create('/webhook/wompi', 'POST', $payload);
    $result = Payments::driver('wompi')->processWebhook($request);

    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(PaymentStatus::Approved);
    expect($result->transaction->fresh()->provider_transaction_id)->toBe('wompi-txn-1');

    Event::assertDispatched(PaymentApproved::class);
});

it('rejects a transaction on wompi webhook with status DECLINED', function () {
    Event::fake([PaymentRejected::class]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-DECLINE', amount: 50000));

    $payload = makeWompiWebhookPayload([
        'id' => 'wompi-txn-2',
        'status' => 'DECLINED',
        'amount_in_cents' => 50000,
        'reference' => $charge->reference,
    ]);

    $request = Request::create('/webhook/wompi', 'POST', $payload);
    $result = Payments::driver('wompi')->processWebhook($request);

    expect($result->status)->toBe(PaymentStatus::Rejected);

    Event::assertDispatched(PaymentRejected::class);
});

it('voids a transaction on wompi webhook with status VOIDED', function () {
    Event::fake([PaymentRejected::class]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-VOID', amount: 50000));

    $payload = makeWompiWebhookPayload([
        'id' => 'wompi-txn-3',
        'status' => 'VOIDED',
        'amount_in_cents' => 50000,
        'reference' => $charge->reference,
    ]);

    $request = Request::create('/webhook/wompi', 'POST', $payload);
    $result = Payments::driver('wompi')->processWebhook($request);

    expect($result->status)->toBe(PaymentStatus::Voided);

    Event::assertDispatched(PaymentRejected::class);
});

it('returns failed when wompi webhook payload has no reference', function () {
    $payload = makeWompiWebhookPayload(['id' => 'wompi-txn-4', 'status' => 'APPROVED']);

    $request = Request::create('/webhook/wompi', 'POST', $payload);
    $result = Payments::driver('wompi')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MISSING_REFERENCE');
});

it('returns not found for wompi webhook with unknown reference', function () {
    $payload = makeWompiWebhookPayload([
        'id' => 'wompi-txn-5',
        'status' => 'APPROVED',
        'reference' => 'REF-DOES-NOT-EXIST-TXN-999999',
    ]);

    $request = Request::create('/webhook/wompi', 'POST', $payload);
    $result = Payments::driver('wompi')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('TRANSACTION_NOT_FOUND');
});

it('treats a repeated wompi webhook on a final transaction as duplicate', function () {
    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-DUP', amount: 50000));

    $payload = makeWompiWebhookPayload([
        'id' => 'wompi-txn-6',
        'status' => 'APPROVED',
        'amount_in_cents' => 50000,
        'reference' => $charge->reference,
    ]);

    $request = Request::create('/webhook/wompi', 'POST', $payload);
    Payments::driver('wompi')->processWebhook($request);

    $result = Payments::driver('wompi')->processWebhook($request);

    expect($result->errorCode)->toBe('DUPLICATE_WEBHOOK');
});

// queryStatus()

it('fails queryStatus for wompi when transaction has no provider id', function () {
    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-NO-PROVIDER-ID', amount: 50000));

    $result = Payments::driver('wompi')->queryStatus((string) $charge->transaction->id);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PROVIDER_ID');
});

it('queries wompi payment status from the api and updates the transaction', function () {
    Http::fake([
        '*/transactions/*' => Http::response([
            'data' => ['id' => 'wompi-txn-7', 'status' => 'APPROVED'],
        ], 200),
    ]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-QUERY', amount: 50000));
    $charge->transaction->update(['provider_transaction_id' => 'wompi-txn-7']);

    $result = Payments::driver('wompi')->queryStatus((string) $charge->transaction->id);

    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(PaymentStatus::Approved);
    expect($charge->transaction->fresh()->status)->toBe(PaymentStatus::Approved);
});

// refund()

it('voids a wompi transaction and marks it as refunded when eligible', function () {
    Event::fake([PaymentRefunded::class]);

    Http::fake([
        '*/transactions/*/void' => Http::response([
            'data' => ['id' => 'wompi-void-1', 'status' => 'VOIDED'],
        ], 200),
    ]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-VOID-REFUND', amount: 50000));
    $charge->transaction->update(['provider_transaction_id' => 'wompi-txn-void']);

    $result = Payments::driver('wompi')->refund($charge->transaction->fresh());

    expect($result->success)->toBeTrue();
    expect($result->refundedAmountInCents)->toBe(50000);
    expect($charge->transaction->fresh()->status)->toBe(PaymentStatus::Voided);
    expect($charge->transaction->fresh()->refunded_amount)->toBe(50000);

    Event::assertDispatched(PaymentRefunded::class);
});

it('reports a wompi refund as requiring manual handling when the void is not confirmed', function () {
    Http::fake([
        '*/transactions/*/void' => Http::response([
            'data' => ['id' => 'wompi-void-2', 'status' => 'APPROVED'],
        ], 200),
    ]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-VOID-FAIL', amount: 50000));
    $charge->transaction->update(['provider_transaction_id' => 'wompi-txn-settled']);

    $result = Payments::driver('wompi')->refund($charge->transaction->fresh());

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MANUAL_REFUND_REQUIRED');
});

it('reports a wompi refund as requiring manual handling when the void request fails', function () {
    Http::fake([
        '*/transactions/*/void' => Http::response(['error' => ['message' => 'Transaction already settled']], 422),
    ]);

    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-VOID-ERROR', amount: 50000));
    $charge->transaction->update(['provider_transaction_id' => 'wompi-txn-error']);

    $result = Payments::driver('wompi')->refund($charge->transaction->fresh());

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MANUAL_REFUND_REQUIRED');
});

it('does not support partial refunds for wompi', function () {
    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-VOID-PARTIAL', amount: 50000));
    $charge->transaction->update(['provider_transaction_id' => 'wompi-txn-partial']);

    $result = Payments::driver('wompi')->refund($charge->transaction->fresh(), amountInCents: 10000);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('REFUND_NOT_SUPPORTED');
});

it('fails wompi refund when transaction has no provider id', function () {
    $charge = Payments::driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-VOID-NO-ID', amount: 50000));

    $result = Payments::driver('wompi')->refund($charge->transaction->fresh());

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PROVIDER_ID');
});
