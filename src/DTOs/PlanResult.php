<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Models\SubscriptionPlan;

/**
 * Result of creating a recurring billing plan.
 */
final readonly class PlanResult
{
    public function __construct(
        public bool $success,
        public ?SubscriptionPlan $plan,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    public static function success(SubscriptionPlan $plan): self
    {
        return new self(success: true, plan: $plan);
    }

    public static function failed(?SubscriptionPlan $plan, string $errorCode, string $errorMessage): self
    {
        return new self(success: false, plan: $plan, errorCode: $errorCode, errorMessage: $errorMessage);
    }

    /**
     * The provider doesn't have real, verified subscription plan support in
     * this package (currently: ePayco — see USAGE.md).
     */
    public static function notSupported(string $reason): self
    {
        return new self(success: false, plan: null, errorCode: 'SUBSCRIPTIONS_NOT_SUPPORTED', errorMessage: $reason);
    }
}
