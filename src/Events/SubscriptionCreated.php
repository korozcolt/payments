<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\SubscriptionResult;
use Korbytes\Payments\Models\Subscription;

class SubscriptionCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Subscription $subscription,
        public readonly SubscriptionResult $subscriptionResult,
    ) {}
}
