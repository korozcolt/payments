<?php

declare(strict_types=1);

use Korbytes\Payments\Core\PaymentManager;
use Korbytes\Payments\Core\SubscriptionScheduler;
use Korbytes\Payments\Core\Tests\Harness;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\SubscriptionStatus;

require_once __DIR__.'/Support.php';

function schedulerHarness(array $extraConfig = []): array
{
    $harness = new Harness($extraConfig + [
        'subscriptions' => ['scheduled_providers' => ['wompi']],
        'drivers' => ['wompi' => ['sandbox' => true, 'public_key' => 'pub', 'private_key' => 'prv', 'integrity_secret' => 'i']],
    ]);
    $subs = $harness->context->subscriptions;
    $manager = new PaymentManager($harness->context);

    return [$harness, $subs, new SubscriptionScheduler($manager, $subs, $harness->context->settings)];
}

function dueWompiSubscription($subs)
{
    $plan = $subs->createPlan([
        'provider' => 'wompi', 'name' => 'Pro', 'amount' => 50000, 'currency' => 'COP',
        'interval' => BillingInterval::Month, 'interval_count' => 1,
    ]);

    return $subs->create([
        'subscription_plan_id' => $plan->getKey(),
        'reference_id' => 'SUB-1',
        'provider' => 'wompi',
        'provider_payment_source_id' => '123',
        'customer_email' => 'a@b.co',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => new DateTimeImmutable('-1 day'),
    ]);
}

it('charges due subscriptions of scheduled providers', function () {
    [$harness, $subs, $scheduler] = schedulerHarness();
    $subscription = dueWompiSubscription($subs);

    $harness->client->queue(201, ['data' => ['id' => 'cycle-1', 'status' => 'APPROVED']]);

    $seen = [];
    $summary = $scheduler->processDue(function ($sub, $result) use (&$seen) {
        $seen[] = [$sub->reference_id, $result->success];
    });

    expect($summary)->toBe(['charged' => 1, 'failed' => 0])
        ->and($seen)->toBe([['SUB-1', true]])
        ->and($subscription->last_charged_at)->not->toBeNull();
});

it('does nothing when no provider is scheduled', function () {
    [, $subs, $scheduler] = schedulerHarness(['subscriptions' => ['scheduled_providers' => []]]);
    dueWompiSubscription($subs);

    expect($scheduler->providers())->toBe([])
        ->and($scheduler->processDue())->toBe(['charged' => 0, 'failed' => 0]);
});
