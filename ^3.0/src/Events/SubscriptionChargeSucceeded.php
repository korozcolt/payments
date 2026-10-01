<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Models\Subscription;

/**
 * Dispatched when a recurring cycle charge succeeds — either via our own
 * scheduler (Wompi) or a webhook confirming a provider-billed cycle.
 */
class SubscriptionChargeSucceeded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly PaymentResult $paymentResult,
    ) {}
}
