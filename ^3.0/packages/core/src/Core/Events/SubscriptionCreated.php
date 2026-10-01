<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core\Events;

use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\DTOs\SubscriptionResult;

/**
 * Framework-neutral event dispatched through PSR-14. Adapters may re-dispatch it as a
 * framework-native event (the Laravel adapter maps it to Korbytes\Payments\Events\SubscriptionCreated).
 */
final class SubscriptionCreated
{
    public function __construct(
        public readonly SubscriptionRecord $subscription,
        public readonly SubscriptionResult $subscriptionResult,
    ) {}
}
