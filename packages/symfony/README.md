# korozcolt/payments-symfony

Symfony bundle for [`korozcolt/payments-core`](../core/README.md): **Wompi**, **MercadoPago** and **ePayco**.

Tested against Symfony 7.4 with a real kernel: bundle configuration, schema command, HTTP webhooks (400/401/200/405), PSR-14 events through `event_dispatcher`, console command.

## Install

```bash
composer require korozcolt/payments-symfony
```

Register the bundle (Flex does it for you; otherwise `config/bundles.php`):

```php
Korbytes\Payments\Symfony\PaymentsBundle::class => ['all' => true],
```

## Configure

```yaml
# config/packages/payments.yaml
payments:
    default: wompi
    urls:
        return: 'https://shop.example/payments/return'
    # the payment tables live in any database reachable by PDO:
    dsn: '%env(PAYMENTS_DATABASE_DSN)%'        # e.g. mysql:host=db;dbname=shop;charset=utf8mb4
    username: '%env(PAYMENTS_DATABASE_USER)%'
    password: '%env(PAYMENTS_DATABASE_PASSWORD)%'
    # ...or reuse an existing \PDO service instead of dsn/username/password:
    # pdo: 'app.pdo'
    drivers:
        wompi:
            sandbox: true
            public_key: '%env(WOMPI_PUBLIC_KEY)%'
            private_key: '%env(WOMPI_PRIVATE_KEY)%'
            integrity_secret: '%env(WOMPI_INTEGRITY_SECRET)%'
            events_secret: '%env(WOMPI_EVENTS_SECRET)%'
```

MercadoPago: `access_token`, `public_key`, `webhook_secret`. ePayco: `public_key`, `private_key`, `p_cust_id_cliente`, `p_key`. Payout credentials go under `payouts:` (separate from `drivers:`).

Options: `enabled`, `subscriptions.scheduled_providers`, `logging.enabled`, `statement_descriptor`, and `http_client` (service id of a PSR-18 client that also implements the PSR-17 factories; default: Symfony HttpClient with a 30 s timeout).

Routes:

```yaml
# config/routes/payments.yaml
payments:
    resource: '@PaymentsBundle/config/routes.php'
```

This exposes `POST /payments/webhooks/{provider}`. Point the provider's dashboard at it.

## Create the tables

```bash
php bin/console payments:schema            # creates them on the configured connection
php bin/console payments:schema --dump     # prints the SQL, e.g. for a Doctrine migration
```

MySQL, PostgreSQL and SQLite are supported.

## Charge

```php
use Korbytes\Payments\Core\Standalone;
use Korbytes\Payments\DTOs\PaymentData;

public function checkout(Standalone $payments): JsonResponse
{
    $charge = $payments->driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-1001', amount: 5000000));

    return $this->json(['reference' => $charge->reference, 'signature' => $charge->signature, 'widget' => $charge->widgetUrl]);
}
```

## Events

The core dispatches through Symfony's `event_dispatcher`, so use normal listeners:

```php
use Korbytes\Payments\Core\Events\PaymentApproved;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

#[AsEventListener]
final class MarkOrderPaid
{
    public function __invoke(PaymentApproved $event): void
    {
        // $event->transaction->reference_id ...
    }
}
```

## Subscriptions (Wompi)

```
0 * * * * php bin/console payments:process-subscriptions
```

Webhook answers are identical to the Laravel, CodeIgniter and Slim adapters: `400` unknown/unavailable provider, `401` bad signature, `200` processed (also `200` + `success:false` for known failures), `500` unexpected error.
