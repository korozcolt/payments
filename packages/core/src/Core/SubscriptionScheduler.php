<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core;

use Korbytes\Payments\Contracts\ConfigProviderInterface;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Repositories\SubscriptionRepositoryInterface;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * Charges due subscription cycles for providers with no recurring-billing
 * engine of their own (settings key `subscriptions.scheduled_providers`).
 *
 * Nothing runs it automatically: call processDue() from the host application's
 * scheduler (cron, Laravel Schedule, Symfony Scheduler, a Spark/CLI command...).
 */
final class SubscriptionScheduler
{
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly SubscriptionRepositoryInterface $subscriptions,
        private readonly ConfigProviderInterface $settings,
    ) {}

    /**
     * @return array<int, string>
     */
    public function providers(): array
    {
        $providers = $this->settings->get('subscriptions.scheduled_providers', ['wompi']);

        return is_array($providers) ? array_values($providers) : [];
    }

    /**
     * Charge every due subscription.
     *
     * @param  (callable(SubscriptionRecord, PaymentResult): void)|null  $onResult  called after each attempt
     * @return array{charged: int, failed: int}
     */
    public function processDue(?callable $onResult = null): array
    {
        $charged = 0;
        $failed = 0;

        $providers = $this->providers();

        if ($providers === []) {
            return ['charged' => 0, 'failed' => 0];
        }

        foreach ($this->subscriptions->due($providers) as $subscription) {
            $provider = $subscription->provider;
            $provider = $provider instanceof PaymentProvider ? $provider->value : (string) $provider;

            $result = $this->manager->driver($provider)->chargeSubscriptionCycle($subscription);

            $result->success ? $charged++ : $failed++;

            if ($onResult) {
                $onResult($subscription, $result);
            }
        }

        return ['charged' => $charged, 'failed' => $failed];
    }
}
