<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Support\ArrayConfig;
use Korbytes\Payments\Support\CallbackTransactionRunner;
use Korbytes\Payments\Testing\InMemoryPayoutRepository;
use Korbytes\Payments\Testing\InMemorySubscriptionRepository;
use Korbytes\Payments\Testing\InMemoryTransactionRepository;
use Psr\Clock\ClockInterface;

function fixedClock(string $at): ClockInterface
{
    return new class($at) implements ClockInterface
    {
        public function __construct(private string $at) {}

        public function now(): DateTimeImmutable
        {
            return new CarbonImmutable($this->at);
        }
    };
}

it('creates, finds, updates and freshes a transaction record', function () {
    $repo = new InMemoryTransactionRepository;

    $txn = $repo->create(['reference_id' => 'ORDER-1', 'status' => PaymentStatus::Pending]);

    expect($txn->getKey())->toBe(1)
        ->and($txn->getAttribute('reference_id'))->toBe('ORDER-1')
        ->and($txn->getAttribute('idempotency_key'))->not->toBeEmpty()
        ->and($txn->isFinal())->toBeFalse()
        ->and($txn->canProcess())->toBeTrue()
        ->and($repo->find(1))->toBe($txn)
        ->and($repo->find(99))->toBeNull();

    $txn->update(['status' => PaymentStatus::Approved]);

    expect($txn->isFinal())->toBeTrue()
        ->and($txn->isSuccessful())->toBeTrue()
        ->and($txn->fresh())->not->toBe($txn)
        ->and($txn->fresh()->getAttribute('status'))->toBe(PaymentStatus::Approved);
});

it('lists only active due subscriptions of the requested providers', function () {
    $repo = new InMemorySubscriptionRepository(fixedClock('2026-01-10 00:00:00'));
    $plan = $repo->createPlan(['provider' => 'wompi', 'name' => 'Pro']);

    $make = fn (string $provider, SubscriptionStatus $status, string $next) => $repo->create([
        'subscription_plan_id' => $plan->getKey(),
        'provider' => $provider,
        'status' => $status,
        'next_billing_date' => new CarbonImmutable($next),
    ]);

    $due = $make('wompi', SubscriptionStatus::Active, '2026-01-09');
    $make('wompi', SubscriptionStatus::Active, '2026-02-01');
    $make('wompi', SubscriptionStatus::Cancelled, '2026-01-09');
    $make('epayco', SubscriptionStatus::Active, '2026-01-09');

    $result = iterator_to_array($repo->due(['wompi']), false);

    expect($result)->toHaveCount(1)
        ->and($result[0])->toBe($due)
        ->and($due->getAttribute('plan')->getAttribute('name'))->toBe('Pro');
});

it('creates payout beneficiaries and payouts', function () {
    $repo = new InMemoryPayoutRepository;

    $beneficiary = $repo->createBeneficiary(['name' => 'Ana']);
    $payout = $repo->create(['payout_beneficiary_id' => $beneficiary->getKey(), 'amount' => 1000]);

    expect($beneficiary->getKey())->toBe(1)
        ->and($payout->getAttribute('amount'))->toBe(1000);
});

it('reads dot-notation config with defaults and per-driver config', function () {
    $config = new ArrayConfig([
        'logging' => ['enabled' => false],
        'drivers' => ['wompi' => ['sandbox' => true]],
    ]);

    expect($config->get('logging.enabled', true))->toBeFalse()
        ->and($config->get('logging.channel', 'stack'))->toBe('stack')
        ->and($config->driverConfig('wompi'))->toBe(['sandbox' => true])
        ->and($config->driverConfig('missing'))->toBe([]);
});

it('runs callbacks through the no-op transaction runner', function () {
    expect((new CallbackTransactionRunner)->run(fn () => 42))->toBe(42);
});
