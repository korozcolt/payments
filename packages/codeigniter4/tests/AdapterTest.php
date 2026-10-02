<?php

declare(strict_types=1);

use CodeIgniter\Events\Events;
use Korbytes\Payments\CodeIgniter\Config\Payments;
use Korbytes\Payments\CodeIgniter\Events\EventBridge;
use Korbytes\Payments\CodeIgniter\PdoFactory;
use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\Core\Events\SubscriptionChargeFailed;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Testing\ArrayRecord;

it('builds PDO DSNs from CodeIgniter database groups', function () {
    expect(PdoFactory::dsn(['DBDriver' => 'MySQLi', 'hostname' => 'db', 'port' => 3307, 'database' => 'shop', 'charset' => 'utf8mb4']))
        ->toBe('mysql:host=db;port=3307;dbname=shop;charset=utf8mb4')
        ->and(PdoFactory::dsn(['DBDriver' => 'MySQLi', 'hostname' => 'localhost', 'database' => 'shop']))
        ->toBe('mysql:host=localhost;dbname=shop;charset=utf8mb4')
        ->and(PdoFactory::dsn(['DBDriver' => 'Postgre', 'hostname' => 'pg', 'port' => '5432', 'database' => 'shop']))
        ->toBe('pgsql:host=pg;port=5432;dbname=shop')
        ->and(PdoFactory::dsn(['DBDriver' => 'SQLite3', 'database' => ':memory:']))
        ->toBe('sqlite::memory:')
        ->and(PdoFactory::dsn(['DBDriver' => 'SQLite3', 'database' => 'payments.db'], '/var/app/writable/'))
        ->toBe('sqlite:/var/app/writable/payments.db')
        ->and(PdoFactory::dsn(['DBDriver' => 'SQLite3', 'database' => '/abs/p.db']))
        ->toBe('sqlite:/abs/p.db');
});

it('rejects database drivers without a bundled schema', function () {
    PdoFactory::dsn(['DBDriver' => 'SQLSRV', 'database' => 'x']);
})->throws(InvalidArgumentException::class, 'not supported');

it('opens a working SQLite connection from a database group', function () {
    $pdo = PdoFactory::fromDatabaseGroup(['DBDriver' => 'SQLite3', 'database' => ':memory:']);

    expect($pdo->query('SELECT 1')->fetchColumn())->toEqual(1);
});

it('forwards core events to CodeIgniter Events under stable names', function () {
    $seen = [];
    Events::on('payments.approved', function ($event) use (&$seen) { $seen[] = $event; });
    Events::on('payments.subscription_charge_failed', function ($event) use (&$seen) { $seen[] = $event; });

    $bridge = new EventBridge;
    $approved = new PaymentApproved(new ArrayRecord(['id' => 1]), WebhookResult::notFound('x'));

    expect($bridge->dispatch($approved))->toBe($approved)
        ->and($seen)->toHaveCount(1)
        ->and($seen[0])->toBe($approved)
        ->and(EventBridge::NAMES[SubscriptionChargeFailed::class])->toBe('payments.subscription_charge_failed');
});

it('turns the config into the core array and drops drivers without credentials', function () {
    $config = new Payments;
    $config->drivers['wompi']['public_key'] = 'pub';
    $config->drivers['wompi']['sandbox'] = false;

    $core = $config->toCoreConfig();

    expect($core['default'])->toBe('wompi')
        ->and($core['drivers']['wompi']['public_key'])->toBe('pub')
        ->and($core['drivers']['wompi']['sandbox'])->toBeFalse()
        ->and($core['drivers']['mercadopago'])->toBe([])
        ->and($core['drivers']['epayco'])->toBe([])
        ->and($core['subscriptions']['scheduled_providers'])->toBe(['wompi']);
});
