<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Korbytes\Payments\Contracts\Records\PayoutBeneficiaryRecord;
use Korbytes\Payments\Contracts\Records\PayoutRecord;
use Korbytes\Payments\Contracts\Repositories\PayoutRepositoryInterface;
use Korbytes\Payments\Models\Payout;
use Korbytes\Payments\Models\PayoutBeneficiary;

final class EloquentPayoutRepository implements PayoutRepositoryInterface
{
    public function createBeneficiary(array $attributes): PayoutBeneficiaryRecord
    {
        return PayoutBeneficiary::create($attributes);
    }

    public function create(array $attributes): PayoutRecord
    {
        return Payout::create($attributes);
    }
}
