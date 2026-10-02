<?php

declare(strict_types=1);

namespace Korbytes\Payments\Pdo;

use Korbytes\Payments\Contracts\Records\PayoutBeneficiaryRecord;
use Korbytes\Payments\Contracts\Records\PayoutRecord;
use Korbytes\Payments\Contracts\Repositories\PayoutRepositoryInterface;

final class PdoPayoutRepository implements PayoutRepositoryInterface
{
    public function __construct(private readonly PdoStore $store) {}

    public function createBeneficiary(array $attributes): PayoutBeneficiaryRecord
    {
        return $this->store->insert(PdoStore::BENEFICIARIES, $attributes);
    }

    public function create(array $attributes): PayoutRecord
    {
        return $this->store->insert(PdoStore::PAYOUTS, $attributes);
    }
}
