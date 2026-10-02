<?php

declare(strict_types=1);

use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Korbytes\Payments\Core\Standalone;

require __DIR__.'/vendor/autoload.php';

/*
 * 1. A PDO connection. SQLite here; MySQL/PostgreSQL work the same
 *    (create the tables with packages/core/resources/schema.{mysql,pgsql}.sql).
 */
$pdo = new PDO('sqlite:'.__DIR__.'/payments.sqlite');

$hasTables = $pdo->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name = 'payment_transactions'")->fetchColumn();
if (! $hasTables) {
    $pdo->exec(file_get_contents(__DIR__.'/vendor/korozcolt/payments-core/resources/schema.sqlite.sql'));
}

/*
 * 2. Configuration: same shape as the Laravel config/payments.php.
 */
$config = [
    'default' => 'wompi',
    'enabled' => ['wompi'],
    'urls' => ['return' => 'https://example.com/payments/return'],
    'drivers' => [
        'wompi' => [
            'sandbox' => true,
            'public_key' => getenv('WOMPI_PUBLIC_KEY') ?: 'pub_test_xxx',
            'private_key' => getenv('WOMPI_PRIVATE_KEY') ?: 'prv_test_xxx',
            'integrity_secret' => getenv('WOMPI_INTEGRITY_SECRET') ?: 'test_integrity_xxx',
            'events_secret' => getenv('WOMPI_EVENTS_SECRET') ?: 'test_events_xxx',
        ],
    ],
];

/*
 * 3. Wire it: any PSR-18 client + any PSR-17 factory that creates requests and streams.
 */
return Standalone::pdo(
    config: $config,
    pdo: $pdo,
    http: new Client(['timeout' => 30]),
    factory: new HttpFactory,
);
