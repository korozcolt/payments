<?php

declare(strict_types=1);

namespace Korbytes\Payments\Pdo;

use Korbytes\Payments\Contracts\Records\PayoutBeneficiaryRecord;
use Korbytes\Payments\Contracts\Records\PayoutRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionPlanRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\Enums\PaymentStatus;

/**
 * A row of one of the package tables, cast to the types drivers expect
 * (enums, arrays, datetimes) and persisted by PdoStore on update().
 */
final class PdoRecord implements PayoutBeneficiaryRecord, PayoutRecord, SubscriptionPlanRecord, SubscriptionRecord, TransactionRecord
{
    /**
     * @param  array<string, mixed>  $attributes  already cast
     */
    public function __construct(
        private readonly PdoStore $store,
        private readonly string $table,
        private array $attributes,
    ) {}

    public function getKey()
    {
        return $this->attributes['id'] ?? null;
    }

    public function getAttribute($key)
    {
        if (! array_key_exists($key, $this->attributes) && $key === 'plan' && $this->table === PdoStore::SUBSCRIPTIONS) {
            return $this->attributes['plan'] = $this->store->findPlan((int) $this->attributes['subscription_plan_id']);
        }

        return $this->attributes[$key] ?? null;
    }

    public function __get($key)
    {
        return $this->getAttribute($key);
    }

    public function __isset(string $key): bool
    {
        return $this->getAttribute($key) !== null;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->attributes = $this->store->updateRow($this->table, (int) $this->getKey(), $attributes);

        return true;
    }

    public function fresh($with = [])
    {
        return $this->store->hydrate($this->table, (int) $this->getKey());
    }

    public function isSuccessful()
    {
        return $this->paymentStatus()?->isSuccessful() ?? false;
    }

    public function isFinal()
    {
        return $this->paymentStatus()?->isFinal() ?? false;
    }

    public function canProcess()
    {
        return ! $this->isFinal();
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->attributes;
    }

    private function paymentStatus(): ?PaymentStatus
    {
        $status = $this->attributes['status'] ?? null;

        return $status instanceof PaymentStatus ? $status : null;
    }
}
