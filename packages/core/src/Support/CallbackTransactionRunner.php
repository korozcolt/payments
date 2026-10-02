<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Korbytes\Payments\Contracts\TransactionRunnerInterface;

/**
 * No-op runner: executes the callback without a database transaction.
 * Suitable for in-memory storage and tests.
 */
final class CallbackTransactionRunner implements TransactionRunnerInterface
{
    public function run(callable $callback): mixed
    {
        return $callback();
    }
}
