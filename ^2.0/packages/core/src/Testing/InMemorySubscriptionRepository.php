<?php

declare(strict_types=1);

namespace Korbytes\Payments\Testing;

use DateTimeInterface;
use Korbytes\Payments\Contracts\Records\SubscriptionPlanRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Repositories\SubscriptionRepositoryInterface;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Psr\Clock\ClockInterface;

final class InMemorySubscriptionRepository implements SubscriptionRepositoryInterface
{
    /** @var array<int, ArrayRecord> */
    private array $plans = [];

    /** @var array<int, ArrayRecord> */
    private array $subscriptions = [];

    public function __construct(private readonly ClockInterface $clock) {}

    public function createPlan(array $attributes): SubscriptionPlanRecord
    {
        $id = count($this->plans) + 1;

        return $this->plans[$id] = new ArrayRecord($attributes + ['id' => $id]);
    }

    public function create(array $attributes): SubscriptionRecord
    {
        $id = count($this->subscriptions) + 1;

        $record = new ArrayRecord($attributes + ['id' => $id, 'failed_charge_attempts' => 0]);

        if (isset($attributes['subscription_plan_id'], $this->plans[$attributes['subscription_plan_id']])) {
            $record->update(['plan' => $this->plans[$attributes['subscription_plan_id']]]);
        }

        return $this->subscriptions[$id] = $record;
    }

    public function findByProviderSubscriptionId(string $providerSubscriptionId): ?SubscriptionRecord
    {
        return $this->firstWhere('provider_subscription_id', $providerSubscriptionId);
    }

    public function findByReferenceId(string $referenceId): ?SubscriptionRecord
    {
        return $this->firstWhere('reference_id', $referenceId);
    }

    private function firstWhere(string $attribute, string $value): ?SubscriptionRecord
    {
        foreach ($this->subscriptions as $subscription) {
            if ((string) $subscription->getAttribute($attribute) === $value) {
                return $subscription;
            }
        }

        return null;
    }

    public function due(array $providers): iterable
    {
        $now = $this->clock->now();

        foreach ($this->subscriptions as $subscription) {
            $status = $subscription->getAttribute('status');
            $provider = $subscription->getAttribute('provider');
            $provider = $provider instanceof PaymentProvider ? $provider->value : $provider;
            $next = $subscription->getAttribute('next_billing_date');

            $active = in_array($status, [SubscriptionStatus::Active, SubscriptionStatus::Trialing], true);

            if ($active && in_array($provider, $providers, true) && $next instanceof DateTimeInterface && $next <= $now) {
                yield $subscription;
            }
        }
    }
}
