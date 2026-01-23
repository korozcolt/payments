# Korozcolt Payments

[![Latest Version on Packagist](https://img.shields.io/packagist/v/korozcolt/payments.svg?style=flat-square)](https://packagist.org/packages/korozcolt/payments)
[![Total Downloads](https://img.shields.io/packagist/dt/korozcolt/payments.svg?style=flat-square)](https://packagist.org/packages/korozcolt/payments)
[![License](https://img.shields.io/packagist/l/korozcolt/payments.svg?style=flat-square)](https://packagist.org/packages/korozcolt/payments)

A unified payment gateway package for Laravel supporting **Wompi**, **MercadoPago**, and **ePayco**. Designed for Colombian and Latin American markets.

## Features

- **Unified API** - Single interface for multiple payment providers
- **Three Providers** - Wompi, MercadoPago, and ePayco out of the box
- **Driver Architecture** - Easily extensible for custom providers
- **Event-Driven** - Listen to payment events for business logic
- **Webhook Handling** - Built-in webhook controller with signature verification
- **Flexible Configuration** - Database or config-based credential management
- **Laravel 10/11/12** - Full compatibility with recent Laravel versions

## Supported Providers

| Provider | Country | Methods |
|----------|---------|---------|
| **Wompi** | Colombia | Cards, PSE, Nequi, Bancolombia, Efecty |
| **MercadoPago** | Latin America | Cards, Bank Transfer, Cash |
| **ePayco** | Colombia | Cards, PSE, Efecty, Baloto |

## Requirements

- PHP 8.2+
- Laravel 10, 11, or 12

## Installation

```bash
composer require korozcolt/payments
```

Publish the configuration:

```bash
php artisan vendor:publish --tag=payments-config
```

Run the migrations:

```bash
php artisan vendor:publish --tag=payments-migrations
php artisan migrate
```

## Quick Start

### 1. Configure Environment

```env
PAYMENTS_DEFAULT=wompi
PAYMENTS_ENABLED=wompi,mercadopago,epayco

WOMPI_PUBLIC_KEY=pub_test_xxx
WOMPI_PRIVATE_KEY=prv_test_xxx
WOMPI_INTEGRITY_KEY=test_integrity_xxx
```

### 2. Create a Payment

```php
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\DTOs\PaymentData;

$paymentData = new PaymentData(
    referenceId: $order->id,
    amount: 150000, // Amount in cents (1,500.00 COP)
    currency: 'COP',
    customer: [
        'name' => 'John Doe',
        'email' => 'john@example.com',
    ],
    returnUrl: 'https://yourapp.com/payment/complete',
);

// Use default driver
$result = Payments::charge($paymentData);

// Or specify a driver
$result = Payments::driver('wompi')->charge($paymentData);
```

### 3. Handle the Result

```php
if ($result->success) {
    return view('payment.widget', [
        'widgetUrl' => $result->widgetUrl,
        'publicKey' => $result->publicKey,
        'reference' => $result->reference,
        'signature' => $result->signature,
        'amount' => $result->amountInCents,
    ]);
}
```

### 4. Listen to Events

```php
// In EventServiceProvider
use Korbytes\Payments\Events\PaymentApproved;

protected $listen = [
    PaymentApproved::class => [
        \App\Listeners\HandlePaymentApproved::class,
    ],
];
```

```php
// app/Listeners/HandlePaymentApproved.php
class HandlePaymentApproved
{
    public function handle(PaymentApproved $event): void
    {
        $transaction = $event->transaction;

        // Update your order, send emails, etc.
        Order::where('id', $transaction->reference_id)
            ->update(['status' => 'paid']);
    }
}
```

## Documentation

- [Installation Guide](INSTALL.md) - Detailed installation instructions
- [Usage Guide](USAGE.md) - Complete usage examples and patterns
- [Changelog](CHANGELOG.md) - Version history

## Configuration

### Environment Variables

```env
# General
PAYMENTS_DEFAULT=wompi
PAYMENTS_ENABLED=wompi,mercadopago,epayco
PAYMENTS_USE_DATABASE=true
PAYMENTS_RETURN_URL=https://yourapp.com/payment/complete
PAYMENTS_WEBHOOK_URL=https://yourapp.com/payments/webhooks

# Wompi
WOMPI_SANDBOX=true
WOMPI_PUBLIC_KEY=pub_test_xxx
WOMPI_PRIVATE_KEY=prv_test_xxx
WOMPI_INTEGRITY_KEY=test_integrity_xxx
WOMPI_EVENTS_SECRET=test_events_xxx

# MercadoPago
MERCADOPAGO_SANDBOX=true
MERCADOPAGO_ACCESS_TOKEN=TEST-xxx
MERCADOPAGO_PUBLIC_KEY=TEST-xxx

# ePayco
EPAYCO_SANDBOX=true
EPAYCO_PUBLIC_KEY=xxx
EPAYCO_PRIVATE_KEY=xxx
EPAYCO_P_CUST_ID=xxx
EPAYCO_P_KEY=xxx
```

### Webhook URLs

Configure these in your payment provider dashboards:

| Provider | URL |
|----------|-----|
| Wompi | `https://yourapp.com/payments/webhooks/wompi` |
| MercadoPago | `https://yourapp.com/payments/webhooks/mercadopago` |
| ePayco | `https://yourapp.com/payments/webhooks/epayco` |

## Events

| Event | Description |
|-------|-------------|
| `PaymentCreated` | Payment intent created |
| `PaymentApproved` | Payment approved by provider |
| `PaymentRejected` | Payment rejected/failed |
| `WebhookReceived` | Webhook received from provider |

## API Reference

### Facades

```php
// Get a driver
Payments::driver('wompi');

// Charge using default driver
Payments::charge($paymentData);

// Check availability
Payments::isAvailable('wompi');
Payments::hasAvailableDriver();

// Get enabled drivers
Payments::enabledDrivers();

// Get active gateways from database
Payments::activeGateways();

// Extend with custom driver
Payments::extend('custom', CustomDriver::class);
```

### PaymentData DTO

```php
$paymentData = new PaymentData(
    referenceId: string,       // Your order/reference ID
    amount: int,               // Amount in cents
    currency: string,          // ISO 4217 code (default: COP)
    customer: array,           // [name, email, phone?]
    returnUrl: ?string,        // Redirect after payment
    webhookUrl: ?string,       // Webhook notification URL
    description: ?string,      // Payment description
    metadata: array,           // Custom data
    items: array,              // Line items
);
```

### PaymentResult DTO

```php
$result->success;           // bool
$result->transaction;       // PaymentTransaction model
$result->provider;          // PaymentProvider enum
$result->widgetUrl;         // Widget script URL
$result->publicKey;         // Provider public key
$result->amountInCents;     // Amount
$result->currency;          // Currency code
$result->reference;         // Transaction reference
$result->signature;         // Payment signature
$result->redirectUrl;       // Redirect URL
$result->extra;             // Provider-specific data
$result->errorCode;         // Error code (if failed)
$result->errorMessage;      // Error message (if failed)
```

## Extending

Create a custom driver:

```php
use Korbytes\Payments\Drivers\AbstractDriver;
use Korbytes\Payments\Contracts\PaymentDriverInterface;

class CustomDriver extends AbstractDriver implements PaymentDriverInterface
{
    public function getName(): string
    {
        return 'custom';
    }

    // Implement other required methods...
}

// Register in a service provider
Payments::extend('custom', CustomDriver::class);
```

## Testing

```bash
composer test
```

## Security

If you discover any security-related issues, please open an issue on the [GitHub repository](https://github.com/korozcolt/payments/issues).

## Credits

- [Korozcolt](https://github.com/korozcolt)
- [All Contributors](../../contributors)

## License

The MIT License (MIT). Please see [License File](LICENSE) for more information.
