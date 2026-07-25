<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\PaymentTransaction;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);
});

function makeEpaycoWebhookSignature(string $refPayco, string $transactionId, string $amount, string $currencyCode): string
{
    $clientId = 'xxx';
    $secretKey = 'xxx';

    return hash('sha256', implode('^', [$clientId, $secretKey, $refPayco, $transactionId, $amount, $currencyCode]));
}

// charge()

it('creates a payment intent with epayco', function () {
    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER-123',
        amount: 50000,
        currency: 'COP',
        customer: [
            'name' => 'Test User',
            'email' => 'test@example.com',
        ],
    );

    $result = Payments::driver('epayco')->charge($paymentData);

    expect($result->success)->toBeTrue();
    expect($result->transaction)->toBeInstanceOf(PaymentTransaction::class);
    expect($result->provider->value)->toBe('epayco');
    expect($result->amountInCents)->toBe(50000);
    expect($result->currency)->toBe('COP');
    expect($result->widgetUrl)->toContain('epayco');
});

it('creates transaction record in database for epayco', function () {
    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER-456',
        amount: 100000,
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
    );

    Payments::driver('epayco')->charge($paymentData);

    $transaction = PaymentTransaction::where('reference_id', 'TEST-ORDER-456')->first();

    expect($transaction)->not->toBeNull();
    expect($transaction->amount)->toBe(100000);
    expect($transaction->status)->toBe(PaymentStatus::Pending);
    expect($transaction->provider->value)->toBe('epayco');
});

it('generates correct reference format for epayco', function () {
    $paymentData = new PaymentData(referenceId: 'MY-ORDER-789', amount: 50000);

    $result = Payments::driver('epayco')->charge($paymentData);

    expect($result->reference)->toMatch('/^REF-MY-ORDER-789-TXN-\d+$/');
});

it('includes customer data and sandbox flag in extra for epayco', function () {
    $paymentData = new PaymentData(
        referenceId: 'TEST-ORDER',
        amount: 50000,
        customer: [
            'name' => 'Juan Pérez',
            'email' => 'juan@test.com',
            'phone' => '+573001234567',
        ],
    );

    $result = Payments::driver('epayco')->charge($paymentData);

    expect($result->extra['customer_name'])->toBe('Juan Pérez');
    expect($result->extra['customer_email'])->toBe('juan@test.com');
    expect($result->extra['customer_phone'])->toBe('+573001234567');
    expect($result->extra['test'])->toBeTrue();
    expect($result->extra['country'])->toBe('co');
});

// verifyWebhookSignature()

it('verifies a valid epayco webhook signature', function () {
    $signature = makeEpaycoWebhookSignature('12345', '99', '500.00', 'COP');

    $request = Request::create('/webhook/epayco', 'POST', [
        'x_signature' => $signature,
        'x_ref_payco' => '12345',
        'x_transaction_id' => '99',
        'x_amount' => '500.00',
        'x_currency_code' => 'COP',
    ]);

    expect(Payments::driver('epayco')->verifyWebhookSignature($request))->toBeTrue();
});

it('rejects an invalid epayco webhook signature', function () {
    $request = Request::create('/webhook/epayco', 'POST', [
        'x_signature' => 'invalid-signature',
        'x_ref_payco' => '12345',
        'x_transaction_id' => '99',
        'x_amount' => '500.00',
        'x_currency_code' => 'COP',
    ]);

    expect(fn () => Payments::driver('epayco')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class);
});

it('throws when x_signature is missing from epayco webhook', function () {
    $request = Request::create('/webhook/epayco', 'POST', [
        'x_ref_payco' => '12345',
    ]);

    expect(fn () => Payments::driver('epayco')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class, 'Missing x_signature');
});

it('throws when required fields for epayco signature verification are missing', function () {
    $request = Request::create('/webhook/epayco', 'POST', [
        'x_signature' => 'whatever',
    ]);

    expect(fn () => Payments::driver('epayco')->verifyWebhookSignature($request))
        ->toThrow(InvalidWebhookSignatureException::class);
});

// processWebhook()

it('approves a transaction on epayco webhook with cod_response 1', function () {
    Event::fake([PaymentApproved::class]);

    $paymentData = new PaymentData(referenceId: 'ORDER-APPROVE', amount: 50000);
    $charge = Payments::driver('epayco')->charge($paymentData);

    $payload = [
        'x_id_invoice' => $charge->reference,
        'x_ref_payco' => 'REFPAYCO-1',
        'x_cod_response' => '1',
        'x_amount' => '500.00',
    ];

    $request = Request::create('/webhook/epayco', 'POST', $payload);
    $result = Payments::driver('epayco')->processWebhook($request);

    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(PaymentStatus::Approved);
    expect($result->transaction->fresh()->provider_transaction_id)->toBe('REFPAYCO-1');

    Event::assertDispatched(PaymentApproved::class);
});

it('rejects a transaction on epayco webhook with cod_response 2', function () {
    Event::fake([PaymentRejected::class]);

    $paymentData = new PaymentData(referenceId: 'ORDER-REJECT', amount: 50000);
    $charge = Payments::driver('epayco')->charge($paymentData);

    $payload = [
        'x_id_invoice' => $charge->reference,
        'x_ref_payco' => 'REFPAYCO-2',
        'x_cod_response' => '2',
        'x_amount' => '500.00',
    ];

    $request = Request::create('/webhook/epayco', 'POST', $payload);
    $result = Payments::driver('epayco')->processWebhook($request);

    expect($result->status)->toBe(PaymentStatus::Rejected);

    Event::assertDispatched(PaymentRejected::class);
});

it('resolves epayco cod_response from text status when code is 0', function () {
    $paymentData = new PaymentData(referenceId: 'ORDER-TEXT', amount: 50000);
    $charge = Payments::driver('epayco')->charge($paymentData);

    $payload = [
        'x_id_invoice' => $charge->reference,
        'x_ref_payco' => 'REFPAYCO-3',
        'x_cod_response' => '0',
        'x_response' => 'Aceptada',
        'x_amount' => '500.00',
    ];

    $request = Request::create('/webhook/epayco', 'POST', $payload);
    $result = Payments::driver('epayco')->processWebhook($request);

    expect($result->status)->toBe(PaymentStatus::Approved);
});

it('fails epayco webhook when amount does not match transaction', function () {
    $paymentData = new PaymentData(referenceId: 'ORDER-MISMATCH', amount: 50000);
    $charge = Payments::driver('epayco')->charge($paymentData);

    $payload = [
        'x_id_invoice' => $charge->reference,
        'x_ref_payco' => 'REFPAYCO-4',
        'x_cod_response' => '1',
        'x_amount' => '999.00',
    ];

    $request = Request::create('/webhook/epayco', 'POST', $payload);
    $result = Payments::driver('epayco')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('AMOUNT_MISMATCH');
});

it('returns not found for epayco webhook with unknown reference', function () {
    $payload = [
        'x_id_invoice' => 'REF-DOES-NOT-EXIST-TXN-999999',
        'x_ref_payco' => 'REFPAYCO-5',
        'x_cod_response' => '1',
        'x_amount' => '500.00',
    ];

    $request = Request::create('/webhook/epayco', 'POST', $payload);
    $result = Payments::driver('epayco')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('TRANSACTION_NOT_FOUND');
});

it('returns failed when epayco webhook payload has no resolvable reference', function () {
    $request = Request::create('/webhook/epayco', 'POST', [
        'x_ref_payco' => 'REFPAYCO-6',
        'x_cod_response' => '1',
    ]);

    $result = Payments::driver('epayco')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MISSING_REFERENCE');
});

it('treats a repeated epayco webhook on a final transaction as duplicate', function () {
    $paymentData = new PaymentData(referenceId: 'ORDER-DUP', amount: 50000);
    $charge = Payments::driver('epayco')->charge($paymentData);

    $payload = [
        'x_id_invoice' => $charge->reference,
        'x_ref_payco' => 'REFPAYCO-7',
        'x_cod_response' => '1',
        'x_amount' => '500.00',
    ];

    $request = Request::create('/webhook/epayco', 'POST', $payload);
    Payments::driver('epayco')->processWebhook($request);

    $result = Payments::driver('epayco')->processWebhook($request);

    expect($result->errorCode)->toBe('DUPLICATE_WEBHOOK');
});

// queryStatus()

it('queries epayco payment status from the api', function () {
    Http::fake([
        '*/restpagos/transaction/response.json*' => Http::response([
            'data' => [
                'x_ref_payco' => 'REFPAYCO-8',
                'x_cod_response' => 1,
            ],
        ], 200),
    ]);

    $paymentData = new PaymentData(referenceId: 'ORDER-QUERY', amount: 50000);
    $charge = Payments::driver('epayco')->charge($paymentData);
    $charge->transaction->update(['provider_transaction_id' => 'REFPAYCO-8']);

    $result = Payments::driver('epayco')->queryStatus((string) $charge->transaction->id);

    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(PaymentStatus::Approved);
    expect($charge->transaction->fresh()->status)->toBe(PaymentStatus::Approved);
});

it('fails queryStatus for epayco when transaction has no provider id', function () {
    $paymentData = new PaymentData(referenceId: 'ORDER-NO-PROVIDER-ID', amount: 50000);
    $charge = Payments::driver('epayco')->charge($paymentData);

    $result = Payments::driver('epayco')->queryStatus((string) $charge->transaction->id);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PROVIDER_ID');
});
