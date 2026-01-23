<?php

declare(strict_types=1);

namespace Korbytes\Payments\Exceptions;

use Exception;

/**
 * Exception thrown when attempting to use a disabled payment driver.
 */
class DriverNotEnabledException extends Exception
{
    public function __construct(
        public readonly string $driver,
        public readonly array $enabledDrivers = [],
    ) {
        $enabled = empty($enabledDrivers) ? 'none' : implode(', ', $enabledDrivers);

        parent::__construct(
            "Payment driver '{$driver}' is not enabled. Enabled drivers: {$enabled}. ".
            "Enable it by adding '{$driver}' to PAYMENTS_ENABLED in your .env file."
        );
    }
}
