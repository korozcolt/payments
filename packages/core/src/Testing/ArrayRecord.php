<?php

declare(strict_types=1);

namespace Korbytes\Payments\Testing;

use Korbytes\Payments\Contracts\Records\PayoutBeneficiaryRecord;
use Korbytes\Payments\Contracts\Records\PayoutRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionPlanRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\Enums\PaymentStatus;

/**
 * Attribute-bag record used by the in-memory repositories and by standalone
 * integrations that do not have an ORM. Implements every record contract.
 */
class ArrayRecord implements PayoutBeneficiaryRecord, PayoutRecord, SubscriptionPlanRecord, SubscriptionRecord, TransactionRecord
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(protected array $attributes = []) {}

    public function getKey()
    {
        return $this->attributes['id'] ?? null;
    }

    public function getAttribute($key)
    {
        return $this->attributes[$key] ?? null;
    }

    public function update(array $attributes = [], array $options = [])
    {
        $this->attributes = array_merge($this->attributes, $attributes);

        return true;
    }

    public function fresh($with = [])
    {
        return clone $this;
    }

    public function isSuccessful()
    {
        return $this->status()?->isSuccessful() ?? false;
    }

    public function isFinal()
    {
        return $this->status()?->isFinal() ?? false;
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

    public function __get(string $name): mixed
    {
        return $this->getAttribute($name);
    }

    public function __isset(string $name): bool
    {
        return isset($this->attributes[$name]);
    }

    private function status(): ?PaymentStatus
    {
        $status = $this->attributes['status'] ?? null;

        return $status instanceof PaymentStatus ? $status : (is_string($status) ? PaymentStatus::tryFrom($status) : null);
    }
}
