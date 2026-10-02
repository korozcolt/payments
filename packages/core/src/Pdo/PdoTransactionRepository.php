<?php

declare(strict_types=1);

namespace Korbytes\Payments\Pdo;

use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\Contracts\Repositories\TransactionRepositoryInterface;
use Korbytes\Payments\Enums\PaymentStatus;

final class PdoTransactionRepository implements TransactionRepositoryInterface
{
    public function __construct(private readonly PdoStore $store) {}

    public function create(array $attributes): TransactionRecord
    {
        return $this->store->insert(PdoStore::TRANSACTIONS, $attributes + [
            'idempotency_key' => $this->store->uuid(),
            'status' => PaymentStatus::Pending,
            'webhook_attempts' => 0,
        ]);
    }

    public function find(int|string $id): ?TransactionRecord
    {
        return $this->store->hydrate(PdoStore::TRANSACTIONS, (int) $id);
    }

    public function findByProviderTransactionId(string $providerTransactionId): ?TransactionRecord
    {
        return $this->store->firstWhere(PdoStore::TRANSACTIONS, 'provider_transaction_id', $providerTransactionId);
    }
}
