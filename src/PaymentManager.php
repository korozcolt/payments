<?php

declare(strict_types=1);

namespace Korbytes\Payments;

use Illuminate\Support\Collection;
use Korbytes\Payments\Contracts\PaymentDriverInterface;
use Korbytes\Payments\Drivers\EpaycoDriver;
use Korbytes\Payments\Drivers\MercadoPagoDriver;
use Korbytes\Payments\Drivers\WompiDriver;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Exceptions\DriverNotEnabledException;
use Korbytes\Payments\Exceptions\PaymentException;
use Korbytes\Payments\Models\PaymentGateway;

/**
 * Payment Manager - Central entry point for the payments package.
 *
 * Provides a unified API to interact with multiple payment providers.
 *
 * Usage:
 *   Payments::driver('wompi')->charge($paymentData);
 *   Payments::charge($paymentData); // Uses default driver
 */
class PaymentManager
{
    /**
     * @var array<string, class-string<PaymentDriverInterface>>
     */
    protected array $drivers = [
        'wompi' => WompiDriver::class,
        'mercadopago' => MercadoPagoDriver::class,
        'epayco' => EpaycoDriver::class,
    ];

    /**
     * @var array<string, PaymentDriverInterface>
     */
    protected array $resolvedDrivers = [];

    /**
     * Get a payment driver instance by name.
     *
     * @throws DriverNotEnabledException
     * @throws PaymentException
     */
    public function driver(PaymentProvider|string|null $driver = null): PaymentDriverInterface
    {
        $driverName = $driver instanceof PaymentProvider
            ? $driver->value
            : ($driver ?? config('payments.default', 'wompi'));

        // Check if driver is enabled
        $enabledDrivers = config('payments.enabled', []);
        if (! empty($enabledDrivers) && ! in_array($driverName, $enabledDrivers)) {
            throw new DriverNotEnabledException($driverName, $enabledDrivers);
        }

        if (isset($this->resolvedDrivers[$driverName])) {
            return $this->resolvedDrivers[$driverName];
        }

        $config = $this->getDriverConfig($driverName);

        return $this->resolvedDrivers[$driverName] = $this->createDriver($driverName, $config);
    }

    /**
     * Shortcut to charge using the default driver.
     */
    public function charge(PaymentData $paymentData): PaymentResult
    {
        return $this->driver()->charge($paymentData);
    }

    /**
     * Get all enabled drivers.
     *
     * @return Collection<string, PaymentDriverInterface>
     */
    public function enabledDrivers(): Collection
    {
        $enabled = config('payments.enabled', array_keys($this->drivers));

        return collect($enabled)
            ->filter(fn ($name) => isset($this->drivers[$name]))
            ->mapWithKeys(fn ($name) => [$name => $this->driver($name)]);
    }

    /**
     * Get all active gateways from the database.
     *
     * @return Collection<int, PaymentGateway>
     */
    public function activeGateways(): Collection
    {
        if (! config('payments.use_database', true)) {
            return collect();
        }

        return PaymentGateway::query()
            ->active()
            ->ordered()
            ->get();
    }

    /**
     * Check if a specific driver is available and enabled.
     */
    public function isAvailable(PaymentProvider|string $driver): bool
    {
        $driverName = $driver instanceof PaymentProvider
            ? $driver->value
            : $driver;

        $enabledDrivers = config('payments.enabled', []);

        if (! empty($enabledDrivers) && ! in_array($driverName, $enabledDrivers)) {
            return false;
        }

        try {
            return $this->driver($driverName)->isConfigured();
        } catch (\Exception) {
            return false;
        }
    }

    /**
     * Check if any payment driver is available.
     */
    public function hasAvailableDriver(): bool
    {
        $enabledDrivers = config('payments.enabled', array_keys($this->drivers));

        foreach ($enabledDrivers as $driverName) {
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
     * Get the configuration for a driver.
     */
    protected function getDriverConfig(string $driverName): array
    {
        // First, try to get from database
        if (config('payments.use_database', true)) {
            $gateway = PaymentGateway::query()
                ->where('provider', $driverName)
                ->first();

            if ($gateway) {
                return $gateway->toDriverConfig();
            }
        }

        // Fall back to config file
        return config("payments.drivers.{$driverName}", []);
    }

    /**
     * Create a driver instance with configuration.
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

        /** @var PaymentDriverInterface $driver */
        $driver = app($driverClass);

        return $driver->configure($config);
    }
}
