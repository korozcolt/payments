<?php

declare(strict_types=1);

namespace Korbytes\Payments\Pdo;

use Korbytes\Payments\Contracts\Records\SubscriptionPlanRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Repositories\SubscriptionRepositoryInterface;

final class PdoSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function __construct(private readonly PdoStore $store) {}

    public function createPlan(array $attributes): SubscriptionPlanRecord
    {
        return $this->store->insert(PdoStore::PLANS, $attributes);
    }

    public function create(array $attributes): SubscriptionRecord
    {
        return $this->store->insert(PdoStore::SUBSCRIPTIONS, $attributes);
    }

    public function findByProviderSubscriptionId(string $providerSubscriptionId): ?SubscriptionRecord
    {
        return $this->store->firstWhere(PdoStore::SUBSCRIPTIONS, 'provider_subscription_id', $providerSubscriptionId);
    }

    public function findByReferenceId(string $referenceId): ?SubscriptionRecord
    {
        return $this->store->firstWhere(PdoStore::SUBSCRIPTIONS, 'reference_id', $referenceId);
    }

    public function due(array $providers): iterable
    {
        return $this->store->dueSubscriptions($providers);
    }
}
