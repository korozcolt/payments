# korozcolt/payments-core

Framework-agnostic core of [`korozcolt/payments`](https://github.com/korozcolt/payments): **Wompi**, **MercadoPago** and **ePayco** drivers for any PHP 8.2+ project — Symfony, CodeIgniter 4, Slim, or plain PHP.

It depends only on PSR interfaces (PSR-18 HTTP client, PSR-17 factories, PSR-3 logger, PSR-14 events, PSR-20 clock) and has **no Laravel dependency** (enforced by a test).

- Using Laravel? Install [`korozcolt/payments`](https://github.com/korozcolt/payments) instead; it wraps this core.
- Using something else? Read on.

## Quick start (plain PHP)

```bash
composer require korozcolt/payments-core guzzlehttp/guzzle
```

1. Create the tables: run `resources/schema.sqlite.sql`, `schema.mysql.sql` or `schema.pgsql.sql` (same tables as the Laravel package).
2. Wire it:

```php
use Korbytes\Payments\Core\Standalone;

$payments = Standalone::pdo(
    config: [
        'default' => 'wompi',
        'urls' => ['return' => 'https://shop.example/return'],
        'drivers' => ['wompi' => [
            'sandbox' => true,
            'public_key' => '...', 'private_key' => '...',
            'integrity_secret' => '...', 'events_secret' => '...',
        ]],
    ],
    pdo: new PDO('mysql:host=...;dbname=...', $user, $pass),
    http: new GuzzleHttp\Client(['timeout' => 30]),   // any PSR-18 client
    factory: new GuzzleHttp\Psr7\HttpFactory,         // any PSR-17 request+stream factory
);
```

3. Charge:

```php
use Korbytes\Payments\DTOs\PaymentData;

$charge = $payments->driver('wompi')->charge(new PaymentData(
    referenceId: 'ORDER-1001',
    amount: 5000000, // cents
    customer: ['email' => 'ana@example.com'],
));
```

4. Receive the provider's webhook (any route/controller):

```php
use Korbytes\Payments\Http\WebhookRequest;

$response = $payments->webhooks()->handle('wompi', WebhookRequest::fromGlobals());

http_response_code($response->status);   // 400 / 401 / 200 / 500, identical across every adapter
echo json_encode($response->body);
```

5. React to payments with PSR-14 events (the built-in dispatcher, or pass your own):

```php
use Korbytes\Payments\Core\Events\PaymentApproved;

$payments->events()->listen(PaymentApproved::class, fn (PaymentApproved $e) => markOrderPaid($e->transaction->reference_id));
```

A complete runnable example lives in [`examples/standalone`](../../examples/standalone) (`composer install && php demo.php`).

## Architecture

| Port | Purpose | Provided implementations |
|---|---|---|
| `Psr\Http\Client\ClientInterface` | outbound HTTP | any PSR-18 client |
| `Psr\Log\LoggerInterface` | logging | any PSR-3 logger |
| `Psr\EventDispatcher\EventDispatcherInterface` | events (`Korbytes\Payments\Core\Events\*`) | `EventDispatcher`, or any PSR-14 |
| `Psr\Clock\ClockInterface` | time | `SystemClock` |
| `ConfigProviderInterface` | settings + credentials | `ArrayConfig` |
| `TransactionRepositoryInterface`, `SubscriptionRepositoryInterface`, `PayoutRepositoryInterface` | persistence | `Pdo*Repository`, `InMemory*` (tests) |
| `TransactionRunnerInterface` | atomic blocks | `PdoTransactionRunner`, `CallbackTransactionRunner` |

Drivers are built from a `DriverContext` bundling those ports; `Standalone::pdo()` assembles it for you.

Implementing your own persistence (Doctrine, CodeIgniter models, ...) means implementing the three repository interfaces and returning objects that satisfy the `Contracts\Records\*` interfaces (see `Testing\ArrayRecord` for the smallest example).

## Notes

- **Timeouts**: PSR-18 has no timeout concept; configure it on your client (e.g. Guzzle `timeout => 30`).
- **Subscriptions** for providers without a billing engine (Wompi): call `$payments->scheduler()->processDue()` from cron.
- Provider capabilities (refunds, subscriptions, payouts) differ per provider — see the main [USAGE.md](../../USAGE.md).
