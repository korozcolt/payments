<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Korbytes\Payments\Contracts\Records\SubscriptionPlanRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Repositories\SubscriptionRepositoryInterface;
use Korbytes\Payments\Models\Subscription;
use Korbytes\Payments\Models\SubscriptionPlan;

final class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function createPlan(array $attributes): SubscriptionPlanRecord
    {
        return SubscriptionPlan::create($attributes);
    }

    public function create(array $attributes): SubscriptionRecord
    {
        return Subscription::create($attributes);
    }

    public function findByProviderSubscriptionId(string $providerSubscriptionId): ?SubscriptionRecord
    {
        return Subscription::where('provider_subscription_id', $providerSubscriptionId)->first();
    }

    public function findByReferenceId(string $referenceId): ?SubscriptionRecord
    {
        return Subscription::where('reference_id', $referenceId)->first();
    }

    public function due(array $providers): iterable
    {
        return Subscription::due()->whereIn('provider', $providers)->get();
    }
}
