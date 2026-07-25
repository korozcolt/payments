<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\PaymentTransaction;
use Korbytes\Payments\Tests\Support\FakeMercadoPagoHttpClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);

    $this->fakeMp = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->fakeMp);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function mercadoPagoWebhookSignature(string $dataId, string $requestId, string $timestamp, string $secret): string
{
    $manifest = "id:{$dataId};request-id:{$requestId};ts:{$timestamp};";

    return hash_hmac('sha256', $manifest, $secret);
}

/**
 * Preference::$init_point/$sandbox_init_point are typed properties without a
 * default, so the SDK's deserializer throws if a fake response omits them.
 */
function fakePreferenceResponse(?string $id, array $overrides = []): array
{
    return array_merge([
        'id' => $id,
        'init_point' => 'https://mercadopago.com/checkout/init',
        'sandbox_init_point' => 'https://mercadopago.com/checkout/sandbox-init',
    ], $overrides);
}

// charge()

it('creates a payment intent with mercadopago', function () {
    $this->fakeMp->respondTo('/checkout/preferences', 201, [
        'id' => 'PREF-123',
        'init_point' => 'https://mercadopago.com/checkout/init',
        'sandbox_init_point' => 'https://mercadopago.com/checkout/sandbox-init',
    ]);

    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER-123',
        amount: 50000,
        currency: 'COP',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
        returnUrl: 'https://example.com/return',
    );

    $result = Payments::driver('mercadopago')->charge($paymentData);

    expect($result->success)->toBeTrue();
    expect($result->transaction)->toBeInstanceOf(PaymentTransaction::class);
    expect($result->provider->value)->toBe('mercadopago');
    expect($result->signature)->toBe('PREF-123');
    expect($result->extra['preference_id'])->toBe('PREF-123');
    // sandbox is true in TestCase config, so the sandbox init point must be used.
    expect($result->extra['init_point'])->toBe('https://mercadopago.com/checkout/sandbox-init');
});

it('creates transaction record in database for mercadopago', function () {
    $this->fakeMp->respondTo('/checkout/preferences', 201, fakePreferenceResponse('PREF-456'));

    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER-456',
        amount: 100000,
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
    );

    Payments::driver('mercadopago')->charge($paymentData);

    $transaction = PaymentTransaction::where('reference_id', 'TEST-ORDER-456')->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(100000);
    expect($transaction->status)->toBe(PaymentStatus::Pending);
    expect($transaction->provider->value)->toBe('mercadopago');
    expect($transaction->provider_transaction_id)->toBe('PREF-456');
});

it('returns a failed result when mercadopago preference creation fails', function () {
    $this->fakeMp->failWith('/checkout/preferences', 400, ['message' => 'invalid access token']);

    $paymentData = new PaymentData(referenceId: 'TEST-ORDER-FAIL', amount: 50000);

    $result = Payments::driver('mercadopago')->charge($paymentData);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('PREFERENCE_CREATION_FAILED');

    $transaction = PaymentTransaction::where('reference_id', 'TEST-ORDER-FAIL')->first();
    expect($transaction)->not->toBeNull();
    expect($transaction->status)->toBe(PaymentStatus::Pending);
});

// verifyWebhookSignature()

it('trusts mercadopago webhooks without a signature header (verified via api)', function () {
    $request = Request::create('/webhook/mercadopago', 'POST', ['data' => ['id' => '123']]);

    expect(Payments::driver('mercadopago')->verifyWebhookSignature($request))->toBeTrue();
});

it('verifies a valid mercadopago webhook signature', function () {
    config()->set('payments.drivers.mercadopago.webhook_secret', 'whs_test_secret');

    $timestamp = '1700000000';
    $requestId = 'req-1';
    $dataId = '999';
    $signature = mercadoPagoWebhookSignature($dataId, $requestId, $timestamp, 'whs_test_secret');

    $request = Request::create('/webhook/mercadopago', 'POST', ['data' => ['id' => $dataId]], [], [], [
        'HTTP_X_SIGNATURE' => "ts={$timestamp},v1={$signature}",
        'HTTP_X_REQUEST_ID' => $requestId,
    ]);

    expect(Payments::driver('mercadopago')->verifyWebhookSignature($request))->toBeTrue();
});

it('rejects an invalid mercadopago webhook signature', function () {
    config()->set('payments.drivers.mercadopago.webhook_secret', 'whs_test_secret');

    $request = Request::create('/webhook/mercadopago', 'POST', ['data' => ['id' => '999']], [], [], [
        'HTTP_X_SIGNATURE' => 'ts=1700000000,v1=invalid',
        'HTTP_X_REQUEST_ID' => 'req-1',
    ]);

    expect(fn () => Payments::driver('mercadopago')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('throws when mercadopago webhook secret is not configured but signature header is present', function () {
    $request = Request::create('/webhook/mercadopago', 'POST', ['data' => ['id' => '999']], [], [], [
        'HTTP_X_SIGNATURE' => 'ts=1700000000,v1=whatever',
        'HTTP_X_REQUEST_ID' => 'req-1',
    ]);

    expect(fn () => Payments::driver('mercadopago')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class, 'Webhook secret not configured');
});

// processWebhook()

it('ignores non-payment mercadopago webhook types', function () {
    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'merchant_order',
        'data' => ['id' => '123'],
    ]);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('IGNORED_WEBHOOK_TYPE');
});

it('fails mercadopago webhook processing when data id is missing', function () {
    $request = Request::create('/webhook/mercadopago', 'POST', ['type' => 'payment']);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MISSING_DATA_ID');
});

it('approves a transaction on mercadopago webhook payment status approved', function () {
    Event::fake([PaymentApproved::class]);

    $this->fakeMp->respondTo('/checkout/preferences', 201, fakePreferenceResponse('PREF-APPROVE'));
    $paymentData = new PaymentData(referenceId: 'ORDER-APPROVE', amount: 50000);
    $charge = Payments::driver('mercadopago')->charge($paymentData);

    $this->fakeMp->respondTo('/v1/payments/555', 200, [
        'id' => 555,
        'status' => 'approved',
        'external_reference' => $charge->reference,
    ]);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'payment',
        'data' => ['id' => '555'],
    ]);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(PaymentStatus::Approved);
    expect($result->transaction->fresh()->provider_transaction_id)->toBe('555');

    Event::assertDispatched(PaymentApproved::class);
});

it('rejects a transaction on mercadopago webhook payment status rejected', function () {
    Event::fake([PaymentRejected::class]);

    $this->fakeMp->respondTo('/checkout/preferences', 201, fakePreferenceResponse('PREF-REJECT'));
    $paymentData = new PaymentData(referenceId: 'ORDER-REJECT', amount: 50000);
    $charge = Payments::driver('mercadopago')->charge($paymentData);

    $this->fakeMp->respondTo('/v1/payments/556', 200, [
        'id' => 556,
        'status' => 'rejected',
        'external_reference' => $charge->reference,
    ]);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'payment',
        'data' => ['id' => '556'],
    ]);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->status)->toBe(PaymentStatus::Rejected);

    Event::assertDispatched(PaymentRejected::class);
});

it('returns not found for mercadopago webhook with unknown external reference', function () {
    $this->fakeMp->respondTo('/v1/payments/777', 200, [
        'id' => 777,
        'status' => 'approved',
        'external_reference' => 'REF-DOES-NOT-EXIST-TXN-999999',
    ]);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'payment',
        'data' => ['id' => '777'],
    ]);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('TRANSACTION_NOT_FOUND');
});

it('fails mercadopago webhook processing when the api call errors', function () {
    $this->fakeMp->failWith('/v1/payments/888', 404, ['message' => 'not found']);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'payment',
        'data' => ['id' => '888'],
    ]);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
});

it('treats a repeated mercadopago webhook on a final transaction as duplicate', function () {
    $this->fakeMp->respondTo('/checkout/preferences', 201, fakePreferenceResponse('PREF-DUP'));
    $paymentData = new PaymentData(referenceId: 'ORDER-DUP', amount: 50000);
    $charge = Payments::driver('mercadopago')->charge($paymentData);

    $this->fakeMp->respondTo('/v1/payments/999', 200, [
        'id' => 999,
        'status' => 'approved',
        'external_reference' => $charge->reference,
    ]);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'payment',
        'data' => ['id' => '999'],
    ]);

    Payments::driver('mercadopago')->processWebhook($request);
    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->errorCode)->toBe('DUPLICATE_WEBHOOK');
});

// queryStatus()

it('fails queryStatus for mercadopago when transaction has no provider id', function () {
    $this->fakeMp->respondTo('/checkout/preferences', 201, fakePreferenceResponse('PREF-NO-ID'));
    $paymentData = new PaymentData(referenceId: 'ORDER-NO-PROVIDER-ID', amount: 50000);
    $charge = Payments::driver('mercadopago')->charge($paymentData);
    $charge->transaction->update(['provider_transaction_id' => null]);

    $result = Payments::driver('mercadopago')->queryStatus((string) $charge->transaction->id);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PROVIDER_ID');
});

it('queries mercadopago payment status from the api and updates the transaction', function () {
    $this->fakeMp->respondTo('/checkout/preferences', 201, fakePreferenceResponse('PREF-QUERY'));
    $paymentData = new PaymentData(referenceId: 'ORDER-QUERY', amount: 50000);
    $charge = Payments::driver('mercadopago')->charge($paymentData);

    $this->fakeMp->respondTo('/v1/payments/321', 200, [
        'id' => 321,
        'status' => 'approved',
    ]);
    $charge->transaction->update(['provider_transaction_id' => '321']);

    $result = Payments::driver('mercadopago')->queryStatus((string) $charge->transaction->id);

    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(PaymentStatus::Approved);
    expect($charge->transaction->fresh()->status)->toBe(PaymentStatus::Approved);
});
