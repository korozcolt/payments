<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Models\PayoutBeneficiary;

final readonly class PayoutBeneficiaryResult
{
    public function __construct(
        public bool $success,
        public ?PayoutBeneficiary $beneficiary,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawPayload = [],
    ) {}

    public static function success(PayoutBeneficiary $beneficiary, array $rawPayload = []): self
    {
        return new self(success: true, beneficiary: $beneficiary, rawPayload: $rawPayload);
    }

    public static function failed(string $errorCode, string $errorMessage, array $rawPayload = []): self
    {
        return new self(success: false, beneficiary: null, errorCode: $errorCode, errorMessage: $errorMessage, rawPayload: $rawPayload);
    }
}
