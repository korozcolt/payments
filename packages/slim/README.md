# korozcolt/payments-slim

Slim 4 / PSR-15 adapter for [`korozcolt/payments-core`](https://github.com/korozcolt/payments-core#readme): **Wompi**, **MercadoPago** and **ePayco**.

```bash
composer require korozcolt/payments-slim korozcolt/payments-core guzzlehttp/guzzle slim/slim slim/psr7
```

## Part of the `korozcolt/payments` ecosystem

The gateway logic (Wompi, MercadoPago, ePayco) is written **once**, in `payments-core`, and each framework gets a thin adapter. Install the adapter for your framework; Composer pulls the core in.

| Package | What it is |
|---|---|
| [payments](https://github.com/korozcolt/payments) | Laravel adapter (also the monorepo) |
| [payments-core](https://github.com/korozcolt/payments-core) | Framework-agnostic core: drivers, manager, webhooks |
| [payments-codeigniter4](https://github.com/korozcolt/payments-codeigniter4) | CodeIgniter 4 adapter |
| **payments-slim** (this package) | Slim 4 / PSR-15 adapter |
| [payments-symfony](https://github.com/korozcolt/payments-symfony) | Symfony bundle |

Why it is split this way, how the pieces relate and what is on the roadmap: **[ecosystem guide](https://github.com/korozcolt/payments/blob/master/docs/ECOSYSTEM.md)**.

## Setup

```php
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\HttpFactory;
use Korbytes\Payments\Core\Standalone;
use Korbytes\Payments\Slim\Payments;
use Slim\Factory\AppFactory;

$payments = Standalone::pdo(
    config: [
        'default' => 'wompi',
        'drivers' => ['wompi' => [
            'sandbox' => true,
            'public_key' => '...', 'private_key' => '...',
            'integrity_secret' => '...', 'events_secret' => '...',
        ]],
    ],
    pdo: new PDO($dsn, $user, $pass),          // tables: packages/core/resources/schema.{sqlite,mysql,pgsql}.sql
    http: new Client(['timeout' => 30]),       // any PSR-18 client
    factory: new HttpFactory,                  // any PSR-17 request+stream factory
);

$app = AppFactory::create();

Payments::registerRoutes($app, $payments);     // POST /payments/webhooks/{provider}
```

Use `Payments::registerRoutes($group, $payments, '/hooks')` to mount it inside a route group or under another prefix.

## Charge

```php
use Korbytes\Payments\DTOs\PaymentData;

$app->post('/checkout', function ($request, $response) use ($payments) {
    $charge = $payments->driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-1001', amount: 5000000));
    $response->getBody()->write(json_encode([
        'reference' => $charge->reference, 'signature' => $charge->signature, 'widget' => $charge->widgetUrl,
    ]));

    return $response->withHeader('Content-Type', 'application/json');
});
```

## Events (PSR-14)

```php
use Korbytes\Payments\Core\Events\PaymentApproved;

$payments->events()->listen(PaymentApproved::class, fn (PaymentApproved $e) => markPaid($e->transaction->reference_id));
```

Pass your own PSR-14 dispatcher to `Standalone::pdo(events: ...)` if you already have one.

## Use it as a plain PSR-15 handler

`Korbytes\Payments\Slim\WebhookRequestHandler` implements `Psr\Http\Server\RequestHandlerInterface` and works in any PSR-15 stack (Mezzio, Laminas, ...): it reads the provider from the `provider` request attribute, or the last path segment.

Answers are identical to the Laravel, CodeIgniter and Symfony adapters: `400` unknown/unavailable provider, `401` bad signature, `200` processed (also `200` + `success:false` for known failures), `500` unexpected error.
