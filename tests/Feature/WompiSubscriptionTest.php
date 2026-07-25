<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Events\SubscriptionCancelled;
use Korbytes\Payments\Events\SubscriptionChargeFailed;
use Korbytes\Payments\Events\SubscriptionChargeSucceeded;
use Korbytes\Payments\Events\SubscriptionCreated;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\Subscription;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);
});

function makeWompiMonthlyPlan(): \Korbytes\Payments\Models\SubscriptionPlan
{
    return Payments::driver('wompi')->createPlan(new PlanData(
        name: 'Pro Monthly',
        amount: 50000,
        interval: BillingInterval::Month,
    ))->plan;
}

// createPlan()

it('creates a local plan for wompi without calling the api', function () {
    $result = Payments::driver('wompi')->createPlan(new PlanData(
        name: 'Pro Monthly',
        amount: 50000,
        interval: BillingInterval::Month,
    ));

    expect($result->success)->toBeTrue();
    expect($result->plan->provider->value)->toBe('wompi');
    expect($result->plan->provider_plan_id)->toBeNull();
    expect($result->plan->amount)->toBe(50000);
});

// createSubscription()

it('fails to create a wompi subscription without an acceptance token', function () {
    $plan = makeWompiMonthlyPlan();

    $result = Payments::driver('wompi')->createSubscription(new SubscriptionData(
        plan: $plan,
        referenceId: 'SUB-1',
        paymentToken: 'tok_test_123',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MISSING_ACCEPTANCE_TOKEN');
});

it('creates a wompi subscription from a tokenized card', function () {
    Event::fake([SubscriptionCreated::class]);

    Http::fake([
        '*/payment_sources' => Http::response([
            'data' => ['id' => 555, 'status' => 'AVAILABLE'],
        ], 201),
    ]);

    $plan = makeWompiMonthlyPlan();

    $result = Payments::driver('wompi')->createSubscription(new SubscriptionData(
        plan: $plan,
        referenceId: 'SUB-2',
        paymentToken: 'tok_test_123',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
        providerOptions: ['acceptance_token' => 'accept_test_token'],
    ));

    expect($result->success)->toBeTrue();
    expect($result->subscription->provider_payment_source_id)->toBe('555');
    expect($result->subscription->status)->toBe(SubscriptionStatus::Active);
    expect($result->subscription->next_billing_date)->not->toBeNull();

    Event::assertDispatched(SubscriptionCreated::class);
});

it('fails to create a wompi subscription when the payment source api call fails', function () {
    Http::fake([
        '*/payment_sources' => Http::response(['error' => ['message' => 'invalid token']], 422),
    ]);

    $plan = makeWompiMonthlyPlan();

    $result = Payments::driver('wompi')->createSubscription(new SubscriptionData(
        plan: $plan,
        referenceId: 'SUB-3',
        paymentToken: 'tok_bad',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
        providerOptions: ['acceptance_token' => 'accept_test_token'],
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('PAYMENT_SOURCE_ERROR');
});

// cancelSubscription()

it('cancels a wompi subscription locally', function () {
    Event::fake([SubscriptionCancelled::class]);

    $plan = makeWompiMonthlyPlan();
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-CANCEL',
        'provider' => 'wompi',
        'provider_payment_source_id' => '999',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => now()->addMonth(),
    ]);

    $result = Payments::driver('wompi')->cancelSubscription($subscription);

    expect($result->success)->toBeTrue();
    expect($result->subscription->status)->toBe(SubscriptionStatus::Cancelled);
    expect($result->subscription->next_billing_date)->toBeNull();

    Event::assertDispatched(SubscriptionCancelled::class);
});

// chargeSubscriptionCycle()

it('charges a wompi subscription cycle successfully', function () {
    Event::fake([SubscriptionChargeSucceeded::class]);

    Http::fake([
        '*/transactions' => Http::response([
            'data' => ['id' => 'wompi-cycle-1', 'status' => 'APPROVED'],
        ], 201),
    ]);

    $plan = makeWompiMonthlyPlan();
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-CHARGE',
        'provider' => 'wompi',
        'provider_payment_source_id' => '777',
        'customer_email' => 'test@example.com',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => now(),
    ]);

    $result = Payments::driver('wompi')->chargeSubscriptionCycle($subscription);

    expect($result->success)->toBeTrue();
    expect($result->transaction->subscription_id)->toBe($subscription->id);
    expect($result->transaction->status)->toBe(PaymentStatus::Approved);
    expect($subscription->fresh()->last_charged_at)->not->toBeNull();
    expect($subscription->fresh()->failed_charge_attempts)->toBe(0);

    Event::assertDispatched(SubscriptionChargeSucceeded::class);
});

it('marks a failed charge attempt when wompi declines a subscription cycle', function () {
    Event::fake([SubscriptionChargeFailed::class]);

    Http::fake([
        '*/transactions' => Http::response([
            'data' => ['id' => 'wompi-cycle-2', 'status' => 'DECLINED'],
        ], 201),
    ]);

    $plan = makeWompiMonthlyPlan();
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-DECLINE',
        'provider' => 'wompi',
        'provider_payment_source_id' => '778',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => now(),
    ]);

    $result = Payments::driver('wompi')->chargeSubscriptionCycle($subscription);

    expect($result->transaction->status)->toBe(PaymentStatus::Rejected);
    expect($subscription->fresh()->failed_charge_attempts)->toBe(1);

    Event::assertDispatched(SubscriptionChargeFailed::class);
});

it('fails to charge a wompi subscription cycle without a stored payment source', function () {
    $plan = makeWompiMonthlyPlan();
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-NO-SOURCE',
        'provider' => 'wompi',
        'status' => SubscriptionStatus::Active,
    ]);

    $result = Payments::driver('wompi')->chargeSubscriptionCycle($subscription);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PAYMENT_SOURCE');
});
