<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Enums\BillingInterval;

/**
 * Input data for creating a recurring billing plan.
 */
final readonly class PlanData
{
    /**
     * @param  int  $amount  Amount per cycle in cents.
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public string $name,
        public int $amount,
        public BillingInterval $interval,
        public string $currency = 'COP',
        public int $intervalCount = 1,
        public ?int $trialDays = null,
        public array $metadata = [],
    ) {}
}
