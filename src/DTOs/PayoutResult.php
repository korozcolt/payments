<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Models\Payout;

final readonly class PayoutResult
{
    public function __construct(
        public bool $success,
        public ?Payout $payout,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawPayload = [],
    ) {}

    public static function success(Payout $payout, array $rawPayload = []): self
    {
        return new self(success: true, payout: $payout, rawPayload: $rawPayload);
    }

    public static function failed(?Payout $payout, string $errorCode, string $errorMessage, array $rawPayload = []): self
    {
        return new self(success: false, payout: $payout, errorCode: $errorCode, errorMessage: $errorMessage, rawPayload: $rawPayload);
    }
}
