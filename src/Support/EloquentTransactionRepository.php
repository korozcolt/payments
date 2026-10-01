<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\Contracts\Repositories\TransactionRepositoryInterface;
use Korbytes\Payments\Models\PaymentTransaction;

final class EloquentTransactionRepository implements TransactionRepositoryInterface
{
    public function create(array $attributes): TransactionRecord
    {
        return PaymentTransaction::create($attributes);
    }

    public function find(int|string $id): ?TransactionRecord
    {
        return PaymentTransaction::find($id);
    }

    public function findByProviderTransactionId(string $providerTransactionId): ?TransactionRecord
    {
        return PaymentTransaction::where('provider_transaction_id', $providerTransactionId)->first();
    }
}
