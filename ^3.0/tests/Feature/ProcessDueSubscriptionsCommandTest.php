<?php

use Illuminate\Support\Facades\Http;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Models\Subscription;
use Korbytes\Payments\Models\SubscriptionPlan;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);
});

function makeDueWompiSubscription(): Subscription
{
    $plan = SubscriptionPlan::create([
        'provider' => 'wompi',
        'name' => 'Pro Monthly',
        'amount' => 50000,
        'currency' => 'COP',
        'interval' => BillingInterval::Month,
        'interval_count' => 1,
    ]);

    return Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-DUE',
        'provider' => 'wompi',
        'provider_payment_source_id' => '123',
        'customer_email' => 'test@example.com',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => now()->subDay(),
    ]);
}

it('reports nothing to do when no providers are scheduled', function () {
    config(['payments.subscriptions.scheduled_providers' => []]);
    $subscription = makeDueWompiSubscription();

    $this->artisan('payments:process-subscriptions')
        ->expectsOutputToContain('No providers configured')
        ->assertExitCode(0);

    expect($subscription->fresh()->last_charged_at)->toBeNull();
});

it('reports no due subscriptions when there are none', function () {
    $this->artisan('payments:process-subscriptions')
        ->expectsOutputToContain('No due subscriptions found')
        ->assertExitCode(0);
});

it('charges a due wompi subscription', function () {
    Http::fake([
        '*/transactions' => Http::response([
            'data' => ['id' => 'wompi-cycle-cmd-1', 'status' => 'APPROVED'],
        ], 201),
    ]);

    $subscription = makeDueWompiSubscription();

    $this->artisan('payments:process-subscriptions')
        ->expectsOutputToContain('Charged subscription')
        ->assertExitCode(0);

    expect($subscription->fresh()->last_charged_at)->not->toBeNull();
    expect($subscription->transactions()->count())->toBe(1);
});

it('does not charge a subscription for a provider not in scheduled_providers', function () {
    config(['payments.subscriptions.scheduled_providers' => ['wompi']]);

    $plan = SubscriptionPlan::create([
        'provider' => 'mercadopago',
        'name' => 'Pro Monthly',
        'amount' => 50000,
        'currency' => 'COP',
        'interval' => BillingInterval::Month,
        'interval_count' => 1,
    ]);

    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-MP-DUE',
        'provider' => 'mercadopago',
        'provider_subscription_id' => 'MP-SUB-DUE',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => now()->subDay(),
    ]);

    $this->artisan('payments:process-subscriptions')
        ->expectsOutputToContain('No due subscriptions found')
        ->assertExitCode(0);

    expect($subscription->fresh()->last_charged_at)->toBeNull();
});

it('does not charge a subscription that is not yet due', function () {
    $plan = SubscriptionPlan::create([
        'provider' => 'wompi',
        'name' => 'Pro Monthly',
        'amount' => 50000,
        'currency' => 'COP',
        'interval' => BillingInterval::Month,
        'interval_count' => 1,
    ]);

    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-NOT-DUE',
        'provider' => 'wompi',
        'provider_payment_source_id' => '123',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => now()->addMonth(),
    ]);

    $this->artisan('payments:process-subscriptions')
        ->expectsOutputToContain('No due subscriptions found')
        ->assertExitCode(0);

    expect($subscription->fresh()->last_charged_at)->toBeNull();
});
