<?php

declare(strict_types=1);

namespace Korbytes\Payments;

use Illuminate\Support\Collection;
use Korbytes\Payments\Contracts\PaymentDriverInterface;
use Korbytes\Payments\Core\PaymentManager as CorePaymentManager;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Exceptions\PaymentException;
use Korbytes\Payments\Models\PaymentGateway;

/**
 * Payment Manager - Central entry point for the payments package (Laravel).
 *
 * Thin Laravel layer over the framework-agnostic core manager: it adds
 * database-backed credentials, Collection return types and container-resolved drivers.
 *
 * Usage:
 *   Payments::driver('wompi')->charge($paymentData);
 *   Payments::charge($paymentData); // Uses default driver
 */
class PaymentManager extends CorePaymentManager
{
    /**
     * Get all enabled drivers.
     *
     * @return Collection<string, PaymentDriverInterface>
     */
    public function enabledDrivers(): Collection
    {
        return collect($this->enabledDriverNames())
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
     * Get the configuration for a driver: database first, then the config file.
     */
    protected function getDriverConfig(string $driverName): array
    {
        if (config('payments.use_database', true)) {
            $gateway = PaymentGateway::query()
                ->where('provider', $driverName)
                ->first();

            if ($gateway) {
                return $gateway->toDriverConfig();
            }
        }

        return parent::getDriverConfig($driverName);
    }

    /**
     * Create a driver instance with configuration, resolved through the container
     * so custom drivers registered with extend() can use dependency injection.
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
