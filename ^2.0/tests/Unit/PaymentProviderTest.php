<?php

use Korbytes\Payments\Enums\PaymentProvider;

it('has correct provider values', function () {
    expect(PaymentProvider::Wompi->value)->toBe('wompi');
    expect(PaymentProvider::MercadoPago->value)->toBe('mercadopago');
    expect(PaymentProvider::Epayco->value)->toBe('epayco');
});

it('has labels for all providers', function () {
    expect(PaymentProvider::Wompi->getLabel())->toBe('Wompi');
    expect(PaymentProvider::MercadoPago->getLabel())->toBe('MercadoPago');
    expect(PaymentProvider::Epayco->getLabel())->toBe('ePayco');
});

it('can create from string value', function () {
    expect(PaymentProvider::from('wompi'))->toBe(PaymentProvider::Wompi);
    expect(PaymentProvider::from('mercadopago'))->toBe(PaymentProvider::MercadoPago);
    expect(PaymentProvider::from('epayco'))->toBe(PaymentProvider::Epayco);
});

it('returns null for invalid provider', function () {
    expect(PaymentProvider::tryFrom('invalid'))->toBeNull();
});
