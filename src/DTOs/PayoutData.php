<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Models\PayoutBeneficiary;

/**
 * Input data for sending a payout to a registered beneficiary.
 */
final readonly class PayoutData
{
    /**
     * @param  int  $amount  Amount in cents.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public PayoutBeneficiary $beneficiary,
        public string $referenceId,
        public int $amount,
        public string $currency = 'COP',
        public ?string $description = null,
        public array $metadata = [],
    ) {}
}
