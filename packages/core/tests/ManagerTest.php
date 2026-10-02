<?php

declare(strict_types=1);

use Korbytes\Payments\Core\PaymentManager;
use Korbytes\Payments\Core\Tests\Harness;
use Korbytes\Payments\Drivers\MercadoPagoDriver;
use Korbytes\Payments\Drivers\WompiDriver;
use Korbytes\Payments\Exceptions\DriverNotEnabledException;
use Korbytes\Payments\Exceptions\PaymentException;

require_once __DIR__.'/Support.php';

function managerWith(array $config): PaymentManager
{
    return new PaymentManager((new Harness($config))->context);
}

it('resolves the default driver from config and caches it', function () {
    $manager = managerWith([
        'default' => 'wompi',
        'drivers' => ['wompi' => ['sandbox' => true, 'public_key' => 'pub']],
    ]);

    $driver = $manager->driver();

    expect($driver)->toBeInstanceOf(WompiDriver::class)
        ->and($driver->isConfigured())->toBeTrue()
        ->and($manager->driver('wompi'))->toBe($driver);
});

it('refuses drivers that are not enabled', function () {
    managerWith(['enabled' => ['wompi']])->driver('epayco');
})->throws(DriverNotEnabledException::class);

it('fails on an unknown driver', function () {
    managerWith([])->driver('nope');
})->throws(PaymentException::class, 'Unknown payment driver');

it('reports availability from enabled list and configuration', function () {
    $manager = managerWith([
        'enabled' => ['wompi', 'epayco'],
        'drivers' => ['wompi' => ['public_key' => 'pub']],
    ]);

    expect($manager->isAvailable('wompi'))->toBeTrue()
        ->and($manager->isAvailable('epayco'))->toBeFalse()       // enabled but unconfigured
        ->and($manager->isAvailable('mercadopago'))->toBeFalse()  // not enabled
        ->and($manager->hasAvailableDriver())->toBeTrue();
});

it('refuses payouts for drivers without a payouts API', function () {
    managerWith(['drivers' => ['mercadopago' => ['access_token' => 'x']]])->payoutDriver('mercadopago');
})->throws(PaymentException::class);

it('lets custom drivers be registered with extend()', function () {
    $manager = managerWith(['drivers' => ['wompi' => ['public_key' => 'pub']]]);
    $manager->extend('wompi', MercadoPagoDriver::class);

    expect($manager->driver('wompi'))->toBeInstanceOf(MercadoPagoDriver::class);
});
