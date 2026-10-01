<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Korbytes\Payments\Contracts\ConfigProviderInterface;

/**
 * Config provider backed by a plain array shaped like config/payments.php.
 */
final class ArrayConfig implements ConfigProviderInterface
{
    /**
     * @param  array<string, mixed>  $config
     */
    public function __construct(private readonly array $config = []) {}

    public function get(string $key, mixed $default = null): mixed
    {
        $value = $this->config;

        foreach (explode('.', $key) as $segment) {
            if (! is_array($value) || ! array_key_exists($segment, $value)) {
                return $default;
            }

            $value = $value[$segment];
        }

        return $value;
    }

    public function driverConfig(string $driver): array
    {
        $config = $this->get('drivers.'.$driver, []);

        return is_array($config) ? $config : [];
    }
}
