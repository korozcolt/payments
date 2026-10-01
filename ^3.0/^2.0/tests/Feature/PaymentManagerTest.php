<?php

use Korbytes\Payments\Contracts\PayoutDriverInterface;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Exceptions\DriverNotEnabledException;
use Korbytes\Payments\Exceptions\PaymentException;
use Korbytes\Payments\Facades\Payments;

it('can get the default driver', function () {
    $driver = Payments::driver();

    expect($driver->getName())->toBe('wompi');
});

it('can get a specific driver by name', function () {
    $driver = Payments::driver('wompi');
    expect($driver->getName())->toBe('wompi');

    $driver = Payments::driver('mercadopago');
    expect($driver->getName())->toBe('mercadopago');

    $driver = Payments::driver('epayco');
    expect($driver->getName())->toBe('epayco');
});

it('can get a driver by enum', function () {
    $driver = Payments::driver(PaymentProvider::Wompi);

    expect($driver->getName())->toBe('wompi');
});

it('throws exception for disabled driver', function () {
    config(['payments.enabled' => ['wompi']]);

    Payments::driver('mercadopago');
})->throws(DriverNotEnabledException::class);

it('can check if driver is available', function () {
    expect(Payments::isAvailable('wompi'))->toBeTrue();
    expect(Payments::isAvailable('mercadopago'))->toBeTrue();
    expect(Payments::isAvailable('epayco'))->toBeTrue();
});

it('reports driver unavailable if not enabled', function () {
    config(['payments.enabled' => ['wompi']]);

    expect(Payments::isAvailable('wompi'))->toBeTrue();
    expect(Payments::isAvailable('mercadopago'))->toBeFalse();
});

it('can check if any driver is available', function () {
    expect(Payments::hasAvailableDriver())->toBeTrue();
});

it('returns enabled drivers', function () {
    $drivers = Payments::enabledDrivers();

    expect($drivers)->toHaveCount(3);
    expect($drivers->keys()->toArray())->toBe(['wompi', 'mercadopago', 'epayco']);
});

it('can extend with custom driver', function () {
    Payments::extend('custom', \Korbytes\Payments\Drivers\WompiDriver::class);

    config(['payments.enabled' => ['custom']]);
    config(['payments.drivers.custom' => config('payments.drivers.wompi')]);

    $driver = Payments::driver('custom');

    expect($driver)->toBeInstanceOf(\Korbytes\Payments\Drivers\WompiDriver::class);
});

it('resolves a payout driver for a provider that supports payouts', function () {
    $driver = Payments::payoutDriver('wompi');

    expect($driver)->toBeInstanceOf(PayoutDriverInterface::class);
});

it('throws when resolving a payout driver for a provider with no payouts support', function () {
    expect(fn () => Payments::payoutDriver('mercadopago'))
        ->toThrow(PaymentException::class);

    try {
        Payments::payoutDriver('mercadopago');
    } catch (PaymentException $e) {
        expect($e->errorCode)->toBe('PAYOUTS_NOT_SUPPORTED');
    }
});
