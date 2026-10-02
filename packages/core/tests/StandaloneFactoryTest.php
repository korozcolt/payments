<?php

declare(strict_types=1);

use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\Core\Standalone;
use Korbytes\Payments\Core\Tests\FakeClient;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Http\WebhookRequest;
use Korbytes\Payments\Support\EventDispatcher;
use Nyholm\Psr7\Factory\Psr17Factory;

require_once __DIR__.'/Support.php';

it('wires a working payments stack from a PSR-18 client and a PDO connection', function () {
    if (! extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped('pdo_sqlite is not available');
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(file_get_contents(__DIR__.'/../resources/schema.sqlite.sql'));

    $payments = Standalone::pdo(
        config: ['drivers' => ['wompi' => [
            'sandbox' => true, 'public_key' => 'pub', 'private_key' => 'prv',
            'integrity_secret' => 'i', 'events_secret' => 'test_events_xxx',
        ]]],
        pdo: $pdo,
        http: new FakeClient,
        factory: new Psr17Factory,
    );

    $approved = [];
    $payments->events()->listen(PaymentApproved::class, function (PaymentApproved $e) use (&$approved) {
        $approved[] = $e->transaction->reference_id;
    });

    $charge = $payments->driver('wompi')->charge(new PaymentData(referenceId: 'STANDALONE-1', amount: 90000));

    $tx = ['id' => 'w-s1', 'status' => 'APPROVED', 'amount_in_cents' => 90000, 'reference' => $charge->reference];
    $response = $payments->webhooks()->handle('wompi', new WebhookRequest(payload: [
        'data' => ['transaction' => $tx],
        'signature' => [
            'properties' => ['id', 'status', 'amount_in_cents'],
            'timestamp' => '1',
            'checksum' => hash('sha256', 'w-s1APPROVED900001test_events_xxx'),
        ],
    ]));

    expect($response->status)->toBe(200)
        ->and($response->body['status'])->toBe('approved')
        ->and($approved)->toBe(['STANDALONE-1'])
        ->and($payments->events())->toBeInstanceOf(EventDispatcher::class)
        ->and($payments->scheduler()->providers())->toBe(['wompi']);

    $row = $pdo->query('SELECT status, provider_transaction_id FROM payment_transactions')->fetch(PDO::FETCH_ASSOC);
    expect($row)->toBe(['status' => PaymentStatus::Approved->value, 'provider_transaction_id' => 'w-s1']);
});
