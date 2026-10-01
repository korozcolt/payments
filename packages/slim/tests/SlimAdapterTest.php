<?php

declare(strict_types=1);

use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\Core\Standalone;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Slim\Payments;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Slim\Factory\AppFactory;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Factory\StreamFactory;

function slimApp(): array
{
    $pdo = new PDO('sqlite::memory:');
    foreach (Korbytes\Payments\Pdo\Schema::statements('sqlite') as $statement) {
        $pdo->exec($statement);
    }

    $client = new class implements ClientInterface
    {
        public function sendRequest(RequestInterface $request): ResponseInterface
        {
            throw new RuntimeException('no outbound HTTP expected');
        }
    };

    $payments = Standalone::pdo(
        config: ['drivers' => ['wompi' => [
            'sandbox' => true, 'public_key' => 'pub', 'private_key' => 'prv',
            'integrity_secret' => 'i', 'events_secret' => 'test_events_xxx',
        ]]],
        pdo: $pdo,
        http: $client,
        factory: new Korbytes\Payments\Slim\Tests\Psr17,
    );

    $app = AppFactory::create();
    Payments::registerRoutes($app, $payments);

    return [$app, $payments, $pdo];
}

function webhookPost(string $uri, array $payload)
{
    $body = (new StreamFactory)->createStream(json_encode($payload));

    return (new ServerRequestFactory)->createServerRequest('POST', $uri)
        ->withHeader('Content-Type', 'application/json')
        ->withBody($body);
}

function signed(array $tx, bool $valid = true): array
{
    $hash = $tx['id'].$tx['status'].$tx['amount_in_cents'];

    return [
        'data' => ['transaction' => $tx],
        'signature' => [
            'properties' => ['id', 'status', 'amount_in_cents'],
            'timestamp' => '1',
            'checksum' => $valid ? hash('sha256', $hash.'1test_events_xxx') : 'bad',
        ],
    ];
}

it('answers 400 for an unknown provider', function () {
    [$app] = slimApp();

    $response = $app->handle(webhookPost('/payments/webhooks/nope', []));

    expect($response->getStatusCode())->toBe(400)
        ->and($response->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and(json_decode((string) $response->getBody(), true))->toBe(['success' => false, 'error' => 'Invalid payment provider']);
});

it('answers 401 for a bad signature', function () {
    [$app] = slimApp();

    $response = $app->handle(webhookPost('/payments/webhooks/wompi', signed(['id' => 'a', 'status' => 'APPROVED', 'amount_in_cents' => 1], false)));

    expect($response->getStatusCode())->toBe(401);
});

it('processes a signed webhook, updates the database and emits events', function () {
    [$app, $payments, $pdo] = slimApp();

    $approved = [];
    $payments->events()->listen(PaymentApproved::class, function ($e) use (&$approved) { $approved[] = $e->transaction->reference_id; });

    $charge = $payments->driver('wompi')->charge(new PaymentData(referenceId: 'SLIM-1', amount: 100000));

    $response = $app->handle(webhookPost('/payments/webhooks/wompi', signed([
        'id' => 'w-slim', 'status' => 'APPROVED', 'amount_in_cents' => 100000, 'reference' => $charge->reference,
    ])));

    expect($response->getStatusCode())->toBe(200)
        ->and(json_decode((string) $response->getBody(), true))->toBe(['success' => true, 'transaction_id' => 1, 'status' => 'approved'])
        ->and($approved)->toBe(['SLIM-1'])
        ->and($pdo->query('SELECT status FROM payment_transactions')->fetchColumn())->toBe('approved');
});

it('mounts under a custom prefix', function () {
    [, $payments] = slimApp();
    $app = AppFactory::create();
    Payments::registerRoutes($app, $payments, '/hooks/pay/');

    expect($app->handle(webhookPost('/hooks/pay/nope', []))->getStatusCode())->toBe(400);
});
