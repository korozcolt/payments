<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Contracts\Records\PayoutBeneficiaryRecord;

final readonly class PayoutBeneficiaryResult
{
    public function __construct(
        public bool $success,
        public ?PayoutBeneficiaryRecord $beneficiary,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawPayload = [],
    ) {}

    public static function success(PayoutBeneficiaryRecord $beneficiary, array $rawPayload = []): self
    {
        return new self(success: true, beneficiary: $beneficiary, rawPayload: $rawPayload);
    }

    public static function failed(string $errorCode, string $errorMessage, array $rawPayload = []): self
    {
        return new self(success: false, beneficiary: null, errorCode: $errorCode, errorMessage: $errorMessage, rawPayload: $rawPayload);
    }
}
