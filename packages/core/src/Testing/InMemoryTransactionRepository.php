<?php

declare(strict_types=1);

namespace Korbytes\Payments\Testing;

use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\Contracts\Repositories\TransactionRepositoryInterface;

final class InMemoryTransactionRepository implements TransactionRepositoryInterface
{
    /** @var array<int|string, ArrayRecord> */
    private array $records = [];

    private int $nextId = 1;

    public function create(array $attributes): TransactionRecord
    {
        $id = $this->nextId++;

        return $this->records[$id] = new ArrayRecord($attributes + [
            'id' => $id,
            'ulid' => bin2hex(random_bytes(13)),
            'idempotency_key' => bin2hex(random_bytes(16)),
            'webhook_attempts' => 0,
        ]);
    }

    public function find(int|string $id): ?TransactionRecord
    {
        return $this->records[$id] ?? null;
    }

    public function findByProviderTransactionId(string $providerTransactionId): ?TransactionRecord
    {
        foreach ($this->records as $record) {
            if ((string) $record->getAttribute('provider_transaction_id') === $providerTransactionId) {
                return $record;
            }
        }

        return null;
    }
}
