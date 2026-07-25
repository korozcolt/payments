<?php

use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\Subscription;
use Korbytes\Payments\Models\SubscriptionPlan;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);
});

it('reports createPlan as not supported for epayco', function () {
    $result = Payments::driver('epayco')->createPlan(new PlanData(
        name: 'Pro Monthly',
        amount: 50000,
        interval: BillingInterval::Month,
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('SUBSCRIPTIONS_NOT_SUPPORTED');
    expect($result->plan)->toBeNull();
});

it('reports createSubscription as not supported for epayco', function () {
    $plan = SubscriptionPlan::create([
        'provider' => 'epayco',
        'name' => 'Pro Monthly',
        'amount' => 50000,
        'currency' => 'COP',
        'interval' => BillingInterval::Month,
        'interval_count' => 1,
    ]);

    $result = Payments::driver('epayco')->createSubscription(new SubscriptionData(
        plan: $plan,
        referenceId: 'SUB-1',
        paymentToken: 'tok_test',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('SUBSCRIPTIONS_NOT_SUPPORTED');
});

it('reports cancelSubscription as not supported for epayco', function () {
    $plan = SubscriptionPlan::create([
        'provider' => 'epayco',
        'name' => 'Pro Monthly',
        'amount' => 50000,
        'currency' => 'COP',
        'interval' => BillingInterval::Month,
        'interval_count' => 1,
    ]);

    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-1',
        'provider' => 'epayco',
        'status' => SubscriptionStatus::Active,
    ]);

    $result = Payments::driver('epayco')->cancelSubscription($subscription);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('SUBSCRIPTIONS_NOT_SUPPORTED');
});

it('reports chargeSubscriptionCycle as not supported for epayco', function () {
    $plan = SubscriptionPlan::create([
        'provider' => 'epayco',
        'name' => 'Pro Monthly',
        'amount' => 50000,
        'currency' => 'COP',
        'interval' => BillingInterval::Month,
        'interval_count' => 1,
    ]);

    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-1',
        'provider' => 'epayco',
        'status' => SubscriptionStatus::Active,
    ]);

    $result = Payments::driver('epayco')->chargeSubscriptionCycle($subscription);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('SUBSCRIPTIONS_NOT_SUPPORTED');
});
