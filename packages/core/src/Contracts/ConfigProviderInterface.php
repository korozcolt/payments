<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts;

/**
 * Source of configuration and credentials. Laravel adapters back this with
 * config('payments') and the PaymentGateway table; standalone apps pass an array.
 */
interface ConfigProviderInterface
{
    /**
     * Read a dot-notation key (e.g. "logging.enabled"), relative to the payments config root.
     */
    public function get(string $key, mixed $default = null): mixed;

    /**
     * Credentials/settings for one driver (e.g. "wompi").
     *
     * @return array<string, mixed>
     */
    public function driverConfig(string $driver): array;
}
