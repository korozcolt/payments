<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Repositories;

use Korbytes\Payments\Contracts\Records\PayoutBeneficiaryRecord;
use Korbytes\Payments\Contracts\Records\PayoutRecord;

interface PayoutRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createBeneficiary(array $attributes): PayoutBeneficiaryRecord;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): PayoutRecord;
}
