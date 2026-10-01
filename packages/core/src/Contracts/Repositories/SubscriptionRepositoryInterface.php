<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Repositories;

use Korbytes\Payments\Contracts\Records\SubscriptionPlanRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;

interface SubscriptionRepositoryInterface
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function createPlan(array $attributes): SubscriptionPlanRecord;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(array $attributes): SubscriptionRecord;

    public function findByProviderSubscriptionId(string $providerSubscriptionId): ?SubscriptionRecord;

    public function findByReferenceId(string $referenceId): ?SubscriptionRecord;

    /**
     * Active subscriptions whose next billing date has passed, limited to the given providers.
     *
     * @param  array<int, string>  $providers
     * @return iterable<SubscriptionRecord>
     */
    public function due(array $providers): iterable;
}
