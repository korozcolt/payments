# korozcolt/payments-codeigniter4

CodeIgniter 4 adapter for [`korozcolt/payments-core`](https://github.com/korozcolt/payments-core#readme): **Wompi**, **MercadoPago** and **ePayco** with one API.
Install, migrate, configure through `.env`. Everything else is auto-discovered by CodeIgniter.

Verified against a fresh `codeigniter4/appstarter` (v4.7): migration, route, Spark command, `service('payments')`, signed webhook over HTTP and `Events::on()` listeners.

## Install

```bash
composer require korozcolt/payments-codeigniter4
php spark migrate -n "Korbytes\Payments\CodeIgniter"
```

The tables are created in the database group named by `payments.dbGroup` (default `default`). MySQLi, Postgre and SQLite3 are supported.

## Configure (`.env`)

```ini
payments.default = wompi
payments.urls.return = https://shop.example/payments/return

payments.drivers.wompi.sandbox = true
payments.drivers.wompi.public_key = pub_test_xxx
payments.drivers.wompi.private_key = prv_test_xxx
payments.drivers.wompi.integrity_secret = test_integrity_xxx
payments.drivers.wompi.events_secret = test_events_xxx
```

MercadoPago: `payments.drivers.mercadopago.access_token`, `.public_key`, `.webhook_secret`.
ePayco: `payments.drivers.epayco.public_key`, `.private_key`, `.p_cust_id_cliente`, `.p_key`.

For anything beyond `.env`, copy `src/Config/Payments.php` to `app/Config/Payments.php` and extend it.

## Charge

```php
use Korbytes\Payments\DTOs\PaymentData;

$charge = service('payments')->driver('wompi')->charge(new PaymentData(
    referenceId: 'ORDER-1001',
    amount: 5000000,            // in cents
    customer: ['email' => 'ana@example.com'],
));
// $charge->reference, $charge->signature, $charge->widgetUrl ... feed the provider's checkout widget
```

## Webhooks

`POST /payments/webhooks/{provider}` is registered automatically (`payments.registerRoutes`, `payments.webhookPrefix`). Point the provider's dashboard at it.

> Exclude it from CSRF protection in `app/Config/Filters.php`:
> `public array $globals = ['before' => ['csrf' => ['except' => ['payments/webhooks/*']]]];`

It answers exactly like the Laravel, Slim and Symfony adapters: `400` unknown/unavailable provider, `401` bad signature, `200` processed (also `200` + `success:false` for known failures so providers do not retry), `500` unexpected error.

## Events

```php
// app/Config/Events.php
use CodeIgniter\Events\Events;

Events::on('payments.approved', static function (\Korbytes\Payments\Core\Events\PaymentApproved $event) {
    // $event->transaction->reference_id, $event->webhookResult ...
});
```

Names: `payments.created`, `payments.approved`, `payments.rejected`, `payments.refunded`, `payments.webhook_received`, `payments.subscription_created`, `payments.subscription_cancelled`, `payments.subscription_charge_succeeded`, `payments.subscription_charge_failed`.

## Subscriptions (Wompi)

Wompi has no recurring-billing engine, so charge due cycles from cron:

```
0 * * * * cd /path/to/app && php spark payments:process-subscriptions
```

## Notes

- The package opens its own PDO connection from your CodeIgniter database group (same database, no table prefix support).
- HTTP uses Guzzle (PSR-18) with a 30 s timeout.
- Logging goes through CodeIgniter's PSR-3 logger (`service('logger')`).
