<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core;

use Korbytes\Payments\Contracts\PaymentDriverInterface;
use Korbytes\Payments\Contracts\PayoutDriverInterface;
use Korbytes\Payments\Drivers\DriverContext;
use Korbytes\Payments\Drivers\EpaycoDriver;
use Korbytes\Payments\Drivers\MercadoPagoDriver;
use Korbytes\Payments\Drivers\WompiDriver;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Exceptions\DriverNotEnabledException;
use Korbytes\Payments\Exceptions\PaymentException;

/**
 * Framework-agnostic entry point: resolves and caches configured drivers.
 *
 *   $manager = new PaymentManager($context);
 *   $manager->driver('wompi')->charge($paymentData);
 *
 * The Laravel package extends this class to add database-backed credentials.
 */
class PaymentManager
{
    /** @var array<string, class-string<PaymentDriverInterface>> */
    protected array $drivers = [
        'wompi' => WompiDriver::class,
        'mercadopago' => MercadoPagoDriver::class,
        'epayco' => EpaycoDriver::class,
    ];

    /** @var array<string, PaymentDriverInterface> */
    protected array $resolvedDrivers = [];

    public function __construct(protected DriverContext $context) {}

    /**
     * @throws DriverNotEnabledException
     * @throws PaymentException
     */
    public function driver(PaymentProvider|string|null $driver = null): PaymentDriverInterface
    {
        $driverName = $this->driverName($driver);

        $enabled = $this->enabledDriverNames(false);
        if (! empty($enabled) && ! in_array($driverName, $enabled)) {
            throw new DriverNotEnabledException($driverName, $enabled);
        }

        if (isset($this->resolvedDrivers[$driverName])) {
            return $this->resolvedDrivers[$driverName];
        }

        return $this->resolvedDrivers[$driverName] = $this->createDriver($driverName, $this->getDriverConfig($driverName));
    }

    public function charge(PaymentData $paymentData): PaymentResult
    {
        return $this->driver()->charge($paymentData);
    }

    /**
     * Get a driver that supports payouts (third-party disbursements).
     *
     * @throws PaymentException when the driver has no payouts API
     */
    public function payoutDriver(PaymentProvider|string|null $driver = null): PayoutDriverInterface
    {
        $driverName = $this->driverName($driver);

        $resolved = $this->driver($driverName);

        if (! $resolved instanceof PayoutDriverInterface) {
            throw PaymentException::payoutsNotSupported(PaymentProvider::from($driverName));
        }

        $resolved->configurePayouts($this->context->settings->get("payouts.{$driverName}", []));

        return $resolved;
    }

    /**
     * Names of the enabled drivers (all registered ones when none are configured).
     *
     * @return array<int, string>
     */
    public function enabledDriverNames(bool $defaultToAll = true): array
    {
        $enabled = $this->context->settings->get('enabled', $defaultToAll ? array_keys($this->drivers) : []);

        return is_array($enabled) ? array_values($enabled) : [];
    }

    public function isAvailable(PaymentProvider|string $driver): bool
    {
        $driverName = $driver instanceof PaymentProvider ? $driver->value : $driver;

        $enabled = $this->enabledDriverNames(false);
        if (! empty($enabled) && ! in_array($driverName, $enabled)) {
            return false;
        }

        try {
            return $this->driver($driverName)->isConfigured();
        } catch (\Exception) {
            return false;
        }
    }

    public function hasAvailableDriver(): bool
    {
        foreach ($this->enabledDriverNames() as $driverName) {
            if ($this->isAvailable($driverName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Register a custom driver.
     *
     * @param  class-string<PaymentDriverInterface>  $driverClass
     */
    public function extend(string $name, string $driverClass): void
    {
        $this->drivers[$name] = $driverClass;
    }

    /**
     * Credentials/settings for a driver. Override to load them from elsewhere (e.g. a database).
     *
     * @return array<string, mixed>
     */
    protected function getDriverConfig(string $driverName): array
    {
        return $this->context->settings->driverConfig($driverName);
    }

    /**
     * @param  array<string, mixed>  $config
     *
     * @throws PaymentException
     */
    protected function createDriver(string $driverName, array $config): PaymentDriverInterface
    {
        $driverClass = $this->drivers[$driverName]
            ?? throw new PaymentException(
                message: "Unknown payment driver: {$driverName}",
                errorCode: 'UNKNOWN_DRIVER',
            );

        return (new $driverClass($this->context))->configure($config);
    }

    protected function driverName(PaymentProvider|string|null $driver): string
    {
        return $driver instanceof PaymentProvider
            ? $driver->value
            : ($driver ?? (string) $this->context->settings->get('default', 'wompi'));
    }
}
