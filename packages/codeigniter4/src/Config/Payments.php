<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter\Config;

use CodeIgniter\Config\BaseConfig;

/**
 * Payments configuration.
 *
 * Override any value from your `.env` (CodeIgniter maps `payments.*` keys onto this class):
 *
 *   payments.default = wompi
 *   payments.drivers.wompi.public_key = pub_test_xxx
 *   payments.drivers.wompi.private_key = prv_test_xxx
 *   payments.drivers.wompi.integrity_secret = test_integrity_xxx
 *   payments.drivers.wompi.events_secret = test_events_xxx
 *   payments.drivers.wompi.sandbox = false
 *
 * or copy this file to app/Config/Payments.php and extend it.
 */
class Payments extends BaseConfig
{
    /** Driver used by Payments::driver() when none is given. */
    public string $default = 'wompi';

    /**
     * Enabled drivers. An empty list enables every driver.
     *
     * @var list<string>
     */
    public array $enabled = ['wompi', 'mercadopago', 'epayco'];

    /**
     * Default redirect/webhook URLs (a PaymentData may override them per charge).
     *
     * @var array<string, string|null>
     */
    public array $urls = [
        'return' => null,
        'webhook' => null,
    ];

    /**
     * Provider credentials. A driver whose credentials are all empty is treated as not configured.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $drivers = [
        'wompi' => [
            'sandbox' => true,
            'public_key' => null,
            'private_key' => null,
            'integrity_secret' => null,
            'events_secret' => null,
        ],
        'mercadopago' => [
            'sandbox' => true,
            'access_token' => null,
            'public_key' => null,
            'webhook_secret' => null,
        ],
        'epayco' => [
            'sandbox' => true,
            'public_key' => null,
            'private_key' => null,
            'p_cust_id_cliente' => null,
            'p_key' => null,
        ],
    ];

    /**
     * Payout (third-party payments) credentials, separate from `drivers`.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $payouts = [];

    /**
     * Providers whose due subscriptions `php spark payments:process-subscriptions` charges.
     * Only list providers with no billing engine of their own (Wompi). Never MercadoPago.
     *
     * @var array{scheduled_providers: list<string>}
     */
    public array $subscriptions = [
        'scheduled_providers' => ['wompi'],
    ];

    /** @var array{enabled: bool} */
    public array $logging = [
        'enabled' => true,
    ];

    /** Text shown on card statements (MercadoPago). */
    public ?string $statement_descriptor = null;

    /** Database group (app/Config/Database.php) the payment tables live in. */
    public string $dbGroup = 'default';

    /** Register POST payments/webhooks/{provider} automatically. */
    public bool $registerRoutes = true;

    /** URI prefix of the automatic webhook route. */
    public string $webhookPrefix = 'payments/webhooks';

    /**
     * The settings in the array shape the core expects (same as the Laravel config/payments.php).
     *
     * @return array<string, mixed>
     */
    public function toCoreConfig(): array
    {
        return [
            'default' => $this->default,
            'enabled' => $this->enabled,
            'urls' => $this->urls,
            'drivers' => $this->withoutEmptyDrivers($this->drivers),
            'payouts' => $this->withoutEmptyDrivers($this->payouts),
            'subscriptions' => $this->subscriptions,
            'logging' => $this->logging,
            'statement_descriptor' => $this->statement_descriptor,
        ];
    }

    /**
     * Credentials left null/empty mean "not configured": drop such drivers so isAvailable() is false for them.
     *
     * @param  array<string, array<string, mixed>>  $drivers
     * @return array<string, array<string, mixed>>
     */
    private function withoutEmptyDrivers(array $drivers): array
    {
        foreach ($drivers as $name => $settings) {
            $credentials = array_filter($settings, fn ($value, $key) => $key !== 'sandbox' && $value !== null && $value !== '', ARRAY_FILTER_USE_BOTH);

            if ($credentials === []) {
                $drivers[$name] = [];
            }
        }

        return $drivers;
    }
}
