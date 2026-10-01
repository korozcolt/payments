<?php

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\Core\Events\PaymentCreated as CorePaymentCreated;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\PlanResult;
use Korbytes\Payments\DTOs\RefundResult;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\DTOs\SubscriptionResult;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Drivers\AbstractDriver;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Events\PaymentCreated;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Http\WebhookRequest;

/*
 * Documents and pins the custom-driver upgrade path described in docs/UPGRADING-2.1.md:
 * a driver registered with Payments::extend() that extends AbstractDriver and uses the
 * DriverContext helpers keeps working under Laravel.
 */

class AcmeDriver extends AbstractDriver
{
    public function getName(): string
    {
        return 'wompi'; // reuses a PaymentProvider case; custom provider enums are out of scope here
    }

    public function getWidgetUrl(): string
    {
        return 'https://acme.test/widget.js';
    }

    public function getPublicKey(): ?string
    {
        return $this->getConfig('public_key');
    }

    public function getBaseUrl(): string
    {
        return 'https://acme.test/v1';
    }

    public function charge(PaymentData $paymentData): PaymentResult
    {
        $remote = $this->makeRequest('POST', '/charges', ['amount' => $paymentData->amount]);

        $transaction = $this->ctx->runner->run(fn () => $this->ctx->transactions->create([
            'reference_id' => $paymentData->referenceId,
            'provider' => PaymentProvider::Wompi,
            'amount' => $paymentData->amount,
            'currency' => 'COP',
            'status' => PaymentStatus::Pending,
            'idempotency_key' => $this->uuid(),
            'provider_response' => $remote,
            'initiated_at' => $this->now(),
        ]));

        $result = PaymentResult::success(
            transaction: $transaction,
            provider: PaymentProvider::Wompi,
            reference: "ACME-{$transaction->getKey()}",
            amountInCents: $paymentData->amount,
            currency: 'COP',
            signature: 'sig',
            widgetUrl: $this->getWidgetUrl(),
            publicKey: $this->getPublicKey() ?? '',
        );

        $this->emit(CorePaymentCreated::class, $transaction, $result);

        return $result;
    }

    public function verifyWebhookSignature(object $request): bool
    {
        return WebhookRequest::from($request)->header('x-acme-token') === $this->getConfig('webhook_token');
    }

    public function processWebhook(object $request): WebhookResult
    {
        return WebhookResult::notFound((string) WebhookRequest::from($request)->input('data.ref'), []);
    }

    public function queryStatus(string $transactionId): WebhookResult
    {
        return WebhookResult::notFound($transactionId, []);
    }

    public function refund(TransactionRecord $transaction, ?int $amountInCents = null): RefundResult
    {
        return RefundResult::notSupported($transaction, 'not supported');
    }

    public function createPlan(PlanData $data): PlanResult
    {
        return PlanResult::failed(null, 'UNSUPPORTED', 'no plans');
    }

    public function createSubscription(SubscriptionData $data): SubscriptionResult
    {
        return SubscriptionResult::failed(null, 'UNSUPPORTED', 'no subscriptions');
    }

    public function cancelSubscription(SubscriptionRecord $subscription): SubscriptionResult
    {
        return SubscriptionResult::failed($subscription, 'UNSUPPORTED', 'no subscriptions');
    }

    public function chargeSubscriptionCycle(SubscriptionRecord $subscription): PaymentResult
    {
        return PaymentResult::failed(errorCode: 'UNSUPPORTED', errorMessage: 'no subscriptions');
    }
}

beforeEach(function () {
    $this->artisan('migrate', ['--database' => 'testing']);

    config([
        'payments.enabled' => ['wompi', 'acme'],
        'payments.drivers.acme' => ['public_key' => 'acme_pub', 'webhook_token' => 'secret'],
        'payments.use_database' => false,
    ]);

    Payments::extend('acme', AcmeDriver::class);
});

it('runs a custom driver through the DriverContext helpers', function () {
    Http::fake(['acme.test/*' => Http::response(['id' => 'remote-1'], 201)]);
    Event::fake([PaymentCreated::class]);

    $result = Payments::driver('acme')->charge(new PaymentData(referenceId: 'ACME-ORDER', amount: 9900));

    expect($result->success)->toBeTrue()
        ->and($result->transaction)->toBeInstanceOf(Korbytes\Payments\Models\PaymentTransaction::class)
        ->and($result->transaction->provider_response)->toBe(['id' => 'remote-1'])
        ->and($result->transaction->status)->toBe(PaymentStatus::Pending);

    Http::assertSent(fn ($request) => $request->url() === 'https://acme.test/v1/charges' && $request['amount'] === 9900);

    // The core event is re-dispatched as the existing Laravel event.
    Event::assertDispatched(PaymentCreated::class, fn (PaymentCreated $e) => $e->transaction->is($result->transaction));
});

it('hands a custom driver an Illuminate request through WebhookRequest::from()', function () {
    $request = Illuminate\Http\Request::create('/hook', 'POST', ['data' => ['ref' => 'R-9']], server: ['HTTP_X_ACME_TOKEN' => 'secret']);

    $driver = Payments::driver('acme');

    expect($driver->verifyWebhookSignature($request))->toBeTrue()
        ->and($driver->processWebhook($request)->errorCode)->toBe('TRANSACTION_NOT_FOUND');
});
