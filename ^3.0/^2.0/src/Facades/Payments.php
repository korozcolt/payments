<?php

declare(strict_types=1);

namespace Korbytes\Payments\Facades;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Facade;
use Korbytes\Payments\Contracts\PaymentDriverInterface;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Models\PaymentGateway;
use Korbytes\Payments\PaymentManager;

/**
 * @method static PaymentDriverInterface driver(PaymentProvider|string $driver = null)
 * @method static PaymentResult charge(PaymentData $paymentData)
 * @method static Collection<string, PaymentDriverInterface> enabledDrivers()
 * @method static Collection<int, PaymentGateway> activeGateways()
 * @method static bool isAvailable(PaymentProvider|string $driver)
 * @method static bool hasAvailableDriver()
 * @method static void extend(string $name, string $driverClass)
 *
 * @see PaymentManager
 */
class Payments extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return 'payments';
    }
}
