<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

/**
 * Input data for registering a payout beneficiary (payee).
 *
 * Field values (legalIdType, personType, accountType, bankCode) are
 * passed through as-is and validated by the provider's own API — see
 * USAGE.md for each provider's accepted values.
 */
final readonly class PayoutBeneficiaryData
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public string $legalIdType,
        public string $legalId,
        public string $personType,
        public string $bankCode,
        public string $accountType,
        public string $accountNumber,
        public string $category = 'providers',
        public ?string $email = null,
        public ?string $phone = null,
        public array $metadata = [],
    ) {}
}
