<?php

declare(strict_types=1);

use Korbytes\Payments\Core\PaymentManager;
use Korbytes\Payments\Core\SubscriptionScheduler;
use Korbytes\Payments\Core\Tests\Harness;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\Drivers\DriverContext;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Http\WebhookRequest;
use Korbytes\Payments\Pdo\PdoPayoutRepository;
use Korbytes\Payments\Pdo\PdoStore;
use Korbytes\Payments\Pdo\PdoSubscriptionRepository;
use Korbytes\Payments\Pdo\PdoTransactionRepository;
use Korbytes\Payments\Pdo\PdoTransactionRunner;
use Korbytes\Payments\Support\SystemClock;

require_once __DIR__.'/Support.php';

/** Harness whose persistence is a real SQLite database through PDO. */
function pdoHarness(array $config = []): array
{
    if (! extension_loaded('pdo_sqlite')) {
        test()->markTestSkipped('pdo_sqlite is not available');
    }

    $pdo = new PDO('sqlite::memory:');
    $pdo->exec(file_get_contents(__DIR__.'/../resources/schema.sqlite.sql'));

    $base = new Harness($config + ['drivers' => ['wompi' => [
        'sandbox' => true, 'public_key' => 'pub', 'private_key' => 'prv',
        'integrity_secret' => 'i', 'events_secret' => 'test_events_xxx',
    ]]]);

    $store = new PdoStore($pdo, new SystemClock);
    $c = $base->context;
    $transactions = new PdoTransactionRepository($store);
    $subscriptions = new PdoSubscriptionRepository($store);

    $context = new DriverContext(
        http: $c->http, logger: $c->logger, settings: $c->settings,
        transactions: $transactions, subscriptions: $subscriptions, payouts: new PdoPayoutRepository($store),
        runner: new PdoTransactionRunner($pdo), events: $c->events, clock: $c->clock,
    );

    return [$base, ['tx' => $transactions, 'sub' => $subscriptions, 'store' => $store], new PaymentManager($context), $context];
}

it('persists a charge and approves it from a webhook through PDO', function () {
    [$base, $store, $manager] = pdoHarness();

    $charge = $manager->driver('wompi')->charge(new PaymentData(referenceId: 'ORDER-PDO', amount: 75000));

    $stored = $store['tx']->find($charge->transaction->getKey());
    expect($stored->reference_id)->toBe('ORDER-PDO')
        ->and($stored->amount)->toBe(75000)
        ->and($stored->provider)->toBe(PaymentProvider::Wompi)
        ->and($stored->status)->toBe(PaymentStatus::Pending)
        ->and($stored->idempotency_key)->not->toBeEmpty()
        ->and(strlen($stored->ulid))->toBe(26)
        ->and($stored->created_at)->not->toBeNull();

    $tx = ['id' => 'w-pdo', 'status' => 'APPROVED', 'amount_in_cents' => 75000, 'reference' => $charge->reference];
    $request = new WebhookRequest(payload: [
        'data' => ['transaction' => $tx],
        'signature' => [
            'properties' => ['id', 'status', 'amount_in_cents'],
            'timestamp' => '1',
            'checksum' => hash('sha256', 'w-pdoAPPROVED750001test_events_xxx'),
        ],
    ]);

    $result = $manager->driver('wompi')->processWebhook($request);

    $fresh = $store['tx']->find($charge->transaction->getKey());
    expect($result->success)->toBeTrue()
        ->and($fresh->status)->toBe(PaymentStatus::Approved)
        ->and($fresh->isFinal())->toBeTrue()
        ->and($fresh->provider_transaction_id)->toBe('w-pdo')
        ->and($fresh->webhook_attempts)->toBe(1)
        ->and($fresh->webhook_payload['data']['transaction']['id'])->toBe('w-pdo')
        ->and($store['tx']->findByProviderTransactionId('w-pdo')->getKey())->toBe($fresh->getKey());
});

it('creates plans and subscriptions and lists the due ones through PDO', function () {
    [$base, $store, $manager, $context] = pdoHarness(['subscriptions' => ['scheduled_providers' => ['wompi']]]);

    $plan = $store['sub']->createPlan([
        'provider' => 'wompi', 'name' => 'Pro', 'amount' => 50000,
        'interval' => BillingInterval::Month, 'interval_count' => 1, 'metadata' => ['tier' => 'pro'],
    ]);

    $due = $store['sub']->create([
        'subscription_plan_id' => $plan->getKey(), 'reference_id' => 'SUB-PDO', 'provider' => PaymentProvider::Wompi,
        'provider_payment_source_id' => '123', 'customer_email' => 'a@b.co',
        'status' => SubscriptionStatus::Active, 'next_billing_date' => new DateTimeImmutable('-1 day'),
    ]);
    $store['sub']->create([
        'subscription_plan_id' => $plan->getKey(), 'reference_id' => 'SUB-LATER', 'provider' => PaymentProvider::Wompi,
        'status' => SubscriptionStatus::Active, 'next_billing_date' => new DateTimeImmutable('+1 day'),
    ]);

    // Plan relation is lazy-loaded and cast.
    $loaded = iterator_to_array($store['sub']->due(['wompi']), false);
    expect($loaded)->toHaveCount(1)
        ->and($loaded[0]->reference_id)->toBe('SUB-PDO')
        ->and($loaded[0]->status)->toBe(SubscriptionStatus::Active)
        ->and($loaded[0]->plan->interval)->toBe(BillingInterval::Month)
        ->and($loaded[0]->plan->metadata)->toBe(['tier' => 'pro']);

    // Scheduler charges it and advances the cycle in the database.
    $base->client->queue(201, ['data' => ['id' => 'cycle-pdo', 'status' => 'APPROVED']]);
    $summary = (new SubscriptionScheduler($manager, $store['sub'], $context->settings))->processDue();

    $after = $store['sub']->findByReferenceId('SUB-PDO');
    expect($summary)->toBe(['charged' => 1, 'failed' => 0])
        ->and($after->last_charged_at)->not->toBeNull()
        ->and($after->next_billing_date > new DateTimeImmutable('+20 days'))->toBeTrue()
        ->and(iterator_to_array($store['sub']->due(['wompi']), false))->toBe([]);
});

it('rolls back the transaction runner on failure', function () {
    [, $store] = pdoHarness();
    $pdo = (fn () => $this->pdo)->call($store['store']);
    $runner = new PdoTransactionRunner($pdo);

    expect(fn () => $runner->run(function () use ($store) {
        $store['sub']->createPlan(['provider' => 'wompi', 'name' => 'X', 'amount' => 1]);
        throw new RuntimeException('fail');
    }))->toThrow(RuntimeException::class);

    expect((int) $pdo->query('SELECT COUNT(*) FROM subscription_plans')->fetchColumn())->toBe(0)
        ->and($runner->run(fn () => 'ok'))->toBe('ok');
});
