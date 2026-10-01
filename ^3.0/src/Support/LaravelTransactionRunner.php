<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Illuminate\Support\Facades\DB;
use Korbytes\Payments\Contracts\TransactionRunnerInterface;

final class LaravelTransactionRunner implements TransactionRunnerInterface
{
    public function run(callable $callback): mixed
    {
        return DB::transaction($callback);
    }
}
