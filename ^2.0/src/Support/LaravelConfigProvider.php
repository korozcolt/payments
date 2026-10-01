<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Korbytes\Payments\Contracts\ConfigProviderInterface;

/**
 * Backs the core's config access with config('payments.*').
 */
final class LaravelConfigProvider implements ConfigProviderInterface
{
    public function get(string $key, mixed $default = null): mixed
    {
        if ($key === 'statement_descriptor') {
            return config('payments.statement_descriptor', config('app.name'));
        }

        return config('payments.'.$key, $default);
    }

    public function driverConfig(string $driver): array
    {
        return config("payments.drivers.{$driver}", []);
    }
}
