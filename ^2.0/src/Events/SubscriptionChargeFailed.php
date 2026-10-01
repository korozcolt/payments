<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Models\Subscription;

/**
 * Dispatched when a recurring cycle charge fails. This package does not
 * implement dunning/auto-cancellation — listen to this event to decide
 * retry policy, notifications, or when to cancel a subscription.
 */
class SubscriptionChargeFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly PaymentResult $paymentResult,
    ) {}
}
