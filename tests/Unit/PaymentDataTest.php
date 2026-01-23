<?php

use Korbytes\Payments\DTOs\PaymentData;

it('can create payment data with constructor', function () {
    $data = new PaymentData(
        referenceId: 'ORDER-123',
        amount: 50000,
        currency: 'COP',
        customer: [
            'name' => 'John Doe',
            'email' => 'john@example.com',
            'phone' => '+573001234567',
        ],
        returnUrl: 'https://example.com/return',
        webhookUrl: 'https://example.com/webhook',
        description: 'Test payment',
        metadata: ['order_id' => 123],
    );

    expect($data->referenceId)->toBe('ORDER-123');
    expect($data->amount)->toBe(50000);
    expect($data->currency)->toBe('COP');
    expect($data->getCustomerName())->toBe('John Doe');
    expect($data->getCustomerEmail())->toBe('john@example.com');
    expect($data->getCustomerPhone())->toBe('+573001234567');
});

it('can create payment data from array', function () {
    $data = PaymentData::fromArray([
        'reference_id' => 'ORDER-456',
        'amount' => 100000,
        'currency' => 'USD',
        'customer' => [
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
        ],
    ]);

    expect($data->referenceId)->toBe('ORDER-456');
    expect($data->amount)->toBe(100000);
    expect($data->currency)->toBe('USD');
});

it('formats amount correctly', function () {
    $data = new PaymentData(
        referenceId: 'ORDER-123',
        amount: 150000,
    );

    expect($data->getFormattedAmount())->toBe('$1.500');
});

it('converts to array', function () {
    $data = new PaymentData(
        referenceId: 'ORDER-123',
        amount: 50000,
        currency: 'COP',
    );

    $array = $data->toArray();

    expect($array)->toHaveKey('reference_id');
    expect($array)->toHaveKey('amount');
    expect($array)->toHaveKey('currency');
    expect($array['reference_id'])->toBe('ORDER-123');
});

it('uses COP as default currency', function () {
    $data = new PaymentData(
        referenceId: 'ORDER-123',
        amount: 50000,
    );

    expect($data->currency)->toBe('COP');
});
