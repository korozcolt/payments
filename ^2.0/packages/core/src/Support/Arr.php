<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

/**
 * Minimal dot-notation array access (replacement for Laravel's data_get).
 */
final class Arr
{
    /**
     * @param  array<mixed>|object  $target
     */
    public static function get(array|object $target, string $key, mixed $default = null): mixed
    {
        foreach (explode('.', $key) as $segment) {
            if (is_array($target) && array_key_exists($segment, $target)) {
                $target = $target[$segment];
            } elseif (is_object($target) && isset($target->{$segment})) {
                $target = $target->{$segment};
            } else {
                return $default;
            }
        }

        return $target;
    }
}
