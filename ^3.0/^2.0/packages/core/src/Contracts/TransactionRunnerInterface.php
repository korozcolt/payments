<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts;

/**
 * Runs a callback atomically. Replaces direct DB::transaction() calls in drivers.
 */
interface TransactionRunnerInterface
{
    /**
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function run(callable $callback): mixed;
}
