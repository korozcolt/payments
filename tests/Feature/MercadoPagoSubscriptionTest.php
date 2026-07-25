<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Events\SubscriptionCancelled;
use Korbytes\Payments\Events\SubscriptionChargeSucceeded;
use Korbytes\Payments\Events\SubscriptionCreated;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\Subscription;
use Korbytes\Payments\Models\SubscriptionPlan;
use Korbytes\Payments\Tests\Support\FakeMercadoPagoHttpClient;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Net\MPDefaultHttpClient;

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);

    $this->fakeMp = new FakeMercadoPagoHttpClient;
    MercadoPagoConfig::setHttpClient($this->fakeMp);
});

afterEach(function () {
    MercadoPagoConfig::setHttpClient(new MPDefaultHttpClient);
});

function makeMercadoPagoMonthlyPlan(FakeMercadoPagoHttpClient $fakeMp, string $providerPlanId = 'MP-PLAN-1'): SubscriptionPlan
{
    $fakeMp->respondTo('/preapproval_plan', 201, [
        'id' => $providerPlanId,
        'status' => 'active',
    ]);

    return Payments::driver('mercadopago')->createPlan(new PlanData(
        name: 'Pro Monthly',
        amount: 50000,
        interval: BillingInterval::Month,
    ))->plan;
}

// createPlan()

it('creates a mercadopago subscription plan via the api', function () {
    $this->fakeMp->respondTo('/preapproval_plan', 201, ['id' => 'MP-PLAN-X', 'status' => 'active']);

    $result = Payments::driver('mercadopago')->createPlan(new PlanData(
        name: 'Pro Monthly',
        amount: 50000,
        interval: BillingInterval::Month,
        trialDays: 7,
    ));

    expect($result->success)->toBeTrue();
    expect($result->plan->provider->value)->toBe('mercadopago');
    expect($result->plan->provider_plan_id)->toBe('MP-PLAN-X');
});

it('fails to create a mercadopago plan when the api call errors', function () {
    $this->fakeMp->failWith('/preapproval_plan', 400, ['message' => 'invalid plan data']);

    $result = Payments::driver('mercadopago')->createPlan(new PlanData(
        name: 'Bad Plan',
        amount: 50000,
        interval: BillingInterval::Month,
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
});

// createSubscription()

it('creates a mercadopago subscription from a tokenized card', function () {
    Event::fake([SubscriptionCreated::class]);

    $plan = makeMercadoPagoMonthlyPlan($this->fakeMp);

    $this->fakeMp->respondTo('/preapproval', 201, [
        'id' => 'MP-SUB-1',
        'status' => 'authorized',
        'next_payment_date' => now()->addMonth()->toIso8601String(),
    ]);

    $result = Payments::driver('mercadopago')->createSubscription(new SubscriptionData(
        plan: $plan,
        referenceId: 'SUB-1',
        paymentToken: 'card_token_123',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
    ));

    expect($result->success)->toBeTrue();
    expect($result->subscription->provider_subscription_id)->toBe('MP-SUB-1');
    expect($result->subscription->status)->toBe(SubscriptionStatus::Active);
    expect($result->subscription->next_billing_date)->not->toBeNull();

    Event::assertDispatched(SubscriptionCreated::class);
});

it('fails to create a mercadopago subscription when the plan has no provider_plan_id', function () {
    $plan = SubscriptionPlan::create([
        'provider' => 'mercadopago',
        'name' => 'Orphan Plan',
        'amount' => 50000,
        'currency' => 'COP',
        'interval' => BillingInterval::Month,
        'interval_count' => 1,
    ]);

    $result = Payments::driver('mercadopago')->createSubscription(new SubscriptionData(
        plan: $plan,
        referenceId: 'SUB-2',
        paymentToken: 'card_token_123',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('MISSING_PROVIDER_PLAN');
});

it('fails to create a mercadopago subscription when the api call errors', function () {
    $plan = makeMercadoPagoMonthlyPlan($this->fakeMp);

    $this->fakeMp->failWith('/preapproval', 400, ['message' => 'invalid card token']);

    $result = Payments::driver('mercadopago')->createSubscription(new SubscriptionData(
        plan: $plan,
        referenceId: 'SUB-3',
        paymentToken: 'bad_token',
        customer: ['name' => 'Test User', 'email' => 'test@example.com'],
    ));

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('API_ERROR');
});

// cancelSubscription()

it('cancels a mercadopago subscription via the api', function () {
    Event::fake([SubscriptionCancelled::class]);

    $plan = makeMercadoPagoMonthlyPlan($this->fakeMp);
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-CANCEL',
        'provider' => 'mercadopago',
        'provider_subscription_id' => 'MP-SUB-CANCEL',
        'status' => SubscriptionStatus::Active,
        'next_billing_date' => now()->addMonth(),
    ]);

    $this->fakeMp->respondTo('/preapproval/MP-SUB-CANCEL', 200, ['id' => 'MP-SUB-CANCEL', 'status' => 'cancelled']);

    $result = Payments::driver('mercadopago')->cancelSubscription($subscription);

    expect($result->success)->toBeTrue();
    expect($result->subscription->status)->toBe(SubscriptionStatus::Cancelled);
    expect($result->subscription->next_billing_date)->toBeNull();

    Event::assertDispatched(SubscriptionCancelled::class);
});

it('fails to cancel a mercadopago subscription with no provider id', function () {
    $plan = makeMercadoPagoMonthlyPlan($this->fakeMp);
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-NO-ID',
        'provider' => 'mercadopago',
        'status' => SubscriptionStatus::Active,
    ]);

    $result = Payments::driver('mercadopago')->cancelSubscription($subscription);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NO_PROVIDER_ID');
});

// chargeSubscriptionCycle()

it('reports chargeSubscriptionCycle as not applicable for mercadopago', function () {
    $plan = makeMercadoPagoMonthlyPlan($this->fakeMp);
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-CHARGE',
        'provider' => 'mercadopago',
        'provider_subscription_id' => 'MP-SUB-CHARGE',
        'status' => SubscriptionStatus::Active,
    ]);

    $result = Payments::driver('mercadopago')->chargeSubscriptionCycle($subscription);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('NOT_APPLICABLE');
});

// processWebhook() — subscription_authorized_payment

it('processes a mercadopago subscription_authorized_payment webhook', function () {
    Event::fake([SubscriptionChargeSucceeded::class]);

    $plan = makeMercadoPagoMonthlyPlan($this->fakeMp);
    $subscription = Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-WEBHOOK',
        'provider' => 'mercadopago',
        'provider_subscription_id' => 'MP-SUB-WEBHOOK',
        'status' => SubscriptionStatus::Active,
    ]);

    $this->fakeMp->respondTo('/v1/payments/9001', 200, [
        'id' => 9001,
        'status' => 'approved',
        'external_reference' => 'SUB-WEBHOOK',
        'transaction_amount' => 500.0,
        'currency_id' => 'COP',
    ]);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'subscription_authorized_payment',
        'data' => ['id' => '9001', 'preapproval_id' => 'MP-SUB-WEBHOOK'],
    ]);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->success)->toBeTrue();
    expect($result->status)->toBe(PaymentStatus::Approved);
    expect($result->transaction->subscription_id)->toBe($subscription->id);
    expect($result->transaction->amount)->toBe(50000);

    Event::assertDispatched(SubscriptionChargeSucceeded::class);
});

it('returns not found for a mercadopago subscription webhook with an unknown preapproval id', function () {
    $this->fakeMp->respondTo('/v1/payments/9002', 200, [
        'id' => 9002,
        'status' => 'approved',
        'external_reference' => 'SUB-DOES-NOT-EXIST',
        'transaction_amount' => 500.0,
        'currency_id' => 'COP',
    ]);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'subscription_authorized_payment',
        'data' => ['id' => '9002', 'preapproval_id' => 'MP-SUB-DOES-NOT-EXIST'],
    ]);

    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->success)->toBeFalse();
    expect($result->errorCode)->toBe('TRANSACTION_NOT_FOUND');
});

it('treats a repeated mercadopago subscription charge webhook as duplicate', function () {
    $plan = makeMercadoPagoMonthlyPlan($this->fakeMp);
    Subscription::create([
        'subscription_plan_id' => $plan->id,
        'reference_id' => 'SUB-WEBHOOK-DUP',
        'provider' => 'mercadopago',
        'provider_subscription_id' => 'MP-SUB-WEBHOOK-DUP',
        'status' => SubscriptionStatus::Active,
    ]);

    $this->fakeMp->respondTo('/v1/payments/9003', 200, [
        'id' => 9003,
        'status' => 'approved',
        'external_reference' => 'SUB-WEBHOOK-DUP',
        'transaction_amount' => 500.0,
        'currency_id' => 'COP',
    ]);

    $request = Request::create('/webhook/mercadopago', 'POST', [
        'type' => 'subscription_authorized_payment',
        'data' => ['id' => '9003', 'preapproval_id' => 'MP-SUB-WEBHOOK-DUP'],
    ]);

    Payments::driver('mercadopago')->processWebhook($request);
    $result = Payments::driver('mercadopago')->processWebhook($request);

    expect($result->errorCode)->toBe('DUPLICATE_WEBHOOK');
});
