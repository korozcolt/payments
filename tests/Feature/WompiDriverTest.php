<?php

use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\PaymentTransaction;

beforeEach(function () {
    // Run migrations for tests
    $this->artisan('migrate', ['--database' => 'testing']);
});

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
