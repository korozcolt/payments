<?php

declare(strict_types=1);

namespace Korbytes\Payments\Testing;

use Korbytes\Payments\Contracts\Records\PayoutBeneficiaryRecord;
use Korbytes\Payments\Contracts\Records\PayoutRecord;
use Korbytes\Payments\Contracts\Repositories\PayoutRepositoryInterface;

final class InMemoryPayoutRepository implements PayoutRepositoryInterface
{
    /** @var array<int, ArrayRecord> */
    private array $beneficiaries = [];

    /** @var array<int, ArrayRecord> */
    private array $payouts = [];

    public function createBeneficiary(array $attributes): PayoutBeneficiaryRecord
    {
        $id = count($this->beneficiaries) + 1;

        return $this->beneficiaries[$id] = new ArrayRecord($attributes + ['id' => $id]);
    }

    public function create(array $attributes): PayoutRecord
    {
        $id = count($this->payouts) + 1;

        return $this->payouts[$id] = new ArrayRecord($attributes + ['id' => $id]);
    }
}
