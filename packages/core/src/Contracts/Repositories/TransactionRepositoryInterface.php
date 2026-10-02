<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Repositories;

use Korbytes\Payments\Contracts\Records\TransactionRecord;

interface TransactionRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): TransactionRecord;

    public function find(int|string $id): ?TransactionRecord;

    public function findByProviderTransactionId(string $providerTransactionId): ?TransactionRecord;
}
