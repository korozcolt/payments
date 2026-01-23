<?php

namespace Korbytes\Payments\Tests;

use Korbytes\Payments\PaymentsServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    protected function getPackageProviders($app): array
    {
        return [
            PaymentsServiceProvider::class,
        ];
    }

    protected function getPackageAliases($app): array
    {
        return [
            'Payments' => \Korbytes\Payments\Facades\Payments::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('payments.default', 'wompi');
        $app['config']->set('payments.enabled', ['wompi', 'mercadopago', 'epayco']);
        $app['config']->set('payments.use_database', false);

        $app['config']->set('payments.drivers.wompi', [
            'sandbox' => true,
            'public_key' => 'pub_test_xxx',
            'private_key' => 'prv_test_xxx',
            'integrity_secret' => 'test_integrity_xxx',
            'events_secret' => 'test_events_xxx',
        ]);

        $app['config']->set('payments.drivers.mercadopago', [
            'sandbox' => true,
            'access_token' => 'TEST-xxx',
            'public_key' => 'TEST-xxx',
        ]);

        $app['config']->set('payments.drivers.epayco', [
            'sandbox' => true,
            'public_key' => 'xxx',
            'private_key' => 'xxx',
            'p_cust_id_cliente' => 'xxx',
            'p_key' => 'xxx',
        ]);
    }
}
