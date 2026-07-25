<?php

declare(strict_types=1);

namespace Korbytes\Payments\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\PlanResult;
use Korbytes\Payments\DTOs\RefundResult;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\DTOs\SubscriptionResult;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentCreated;
use Korbytes\Payments\Events\PaymentRefunded;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Events\SubscriptionCancelled;
use Korbytes\Payments\Events\SubscriptionChargeFailed;
use Korbytes\Payments\Events\SubscriptionChargeSucceeded;
use Korbytes\Payments\Events\SubscriptionCreated;
use Korbytes\Payments\Events\WebhookReceived;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Models\PaymentTransaction;
use Korbytes\Payments\Models\Subscription;
use Korbytes\Payments\Models\SubscriptionPlan;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Client\Payment\PaymentRefundClient;
use MercadoPago\Client\PreApproval\PreApprovalClient;
use MercadoPago\Client\PreApprovalPlan\PreApprovalPlanClient;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\MercadoPagoConfig;

/**
 * MercadoPago Payment Driver Implementation.
 *
 * @see https://www.mercadopago.com.co/developers/es/docs
 * @see https://github.com/mercadopago/sdk-php
 */
class MercadoPagoDriver extends AbstractDriver
{
    /**
     * Map MercadoPago payment statuses to internal statuses.
     */
    protected const array STATUS_MAP = [
        'pending' => PaymentStatus::Pending,
        'approved' => PaymentStatus::Approved,
        'authorized' => PaymentStatus::Pending,
        'in_process' => PaymentStatus::Pending,
        'in_mediation' => PaymentStatus::Pending,
        'rejected' => PaymentStatus::Rejected,
        'cancelled' => PaymentStatus::Voided,
        'refunded' => PaymentStatus::Refunded,
        'charged_back' => PaymentStatus::Refunded,
    ];

    public function getName(): string
    {
        return PaymentProvider::MercadoPago->value;
    }

    public function getWidgetUrl(): string
    {
        return $this->getConfig('widget_url', 'https://sdk.mercadopago.com/js/v2');
    }

    public function getPublicKey(): ?string
    {
        return $this->getConfig('public_key');
    }

    public function getBaseUrl(): string
    {
        return $this->getConfig('base_url', 'https://api.mercadopago.com');
    }

    public function charge(PaymentData $paymentData): PaymentResult
    {
        $this->log('info', 'Creating payment intent', [
            'reference_id' => $paymentData->referenceId,
            'amount' => $paymentData->amount,
        ]);

        $this->configureSdk();

        // Create transaction record
        $transaction = DB::transaction(function () use ($paymentData) {
            return PaymentTransaction::create([
                'reference_id' => $paymentData->referenceId,
                'provider' => PaymentProvider::MercadoPago,
                'amount' => $paymentData->amount,
                'currency' => $paymentData->currency,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => (string) Str::uuid(),
                'metadata' => $paymentData->metadata,
                'initiated_at' => now(),
            ]);
        });

        $reference = $this->generateReference($paymentData->referenceId, $transaction->id);
        $redirectUrl = $paymentData->returnUrl ?? config('payments.urls.return');
        $webhookUrl = $paymentData->webhookUrl ?? config('payments.urls.webhook');

        // Create MercadoPago Preference
        $preferenceClient = new PreferenceClient;

        $preferenceData = [
            'items' => $this->buildItems($paymentData),
            'payer' => [
                'email' => $paymentData->getCustomerEmail(),
                'name' => $paymentData->getCustomerName(),
            ],
            'back_urls' => [
                'success' => $redirectUrl.'?status=approved',
                'failure' => $redirectUrl.'?status=rejected',
                'pending' => $redirectUrl.'?status=pending',
            ],
            'auto_return' => 'approved',
            'external_reference' => $reference,
            'notification_url' => $webhookUrl,
            'statement_descriptor' => config('app.name'),
        ];

        try {
            $preference = $preferenceClient->create($preferenceData);

            $transaction->update([
                'provider_transaction_id' => $preference->id,
            ]);

            $this->log('info', 'Payment intent created', [
                'transaction_id' => $transaction->id,
                'preference_id' => $preference->id,
                'reference' => $reference,
            ]);

            $result = PaymentResult::success(
                transaction: $transaction->fresh(),
                provider: PaymentProvider::MercadoPago,
                widgetUrl: $this->getWidgetUrl(),
                publicKey: $this->getPublicKey() ?? $this->getConfig('access_token'),
                amountInCents: $paymentData->amount,
                currency: $paymentData->currency,
                reference: $reference,
                signature: $preference->id,
                redirectUrl: $redirectUrl,
                extra: [
                    'preference_id' => $preference->id,
                    'init_point' => $this->isSandbox()
                        ? $preference->sandbox_init_point
                        : $preference->init_point,
                    'customer_email' => $paymentData->getCustomerEmail(),
                    'customer_name' => $paymentData->getCustomerName(),
                ],
            );

            PaymentCreated::dispatch($transaction->fresh(), $result);

            return $result;

        } catch (\Exception $e) {
            $this->log('error', 'Failed to create preference', [
                'error' => $e->getMessage(),
            ]);

            return PaymentResult::failed(
                errorCode: 'PREFERENCE_CREATION_FAILED',
                errorMessage: $e->getMessage(),
                transaction: $transaction,
            );
        }
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $this->log('debug', 'Verifying webhook signature');

        $xSignature = $request->header('x-signature');
        $xRequestId = $request->header('x-request-id');

        if (! $xSignature || ! $xRequestId) {
            $this->log('debug', 'No signature header, will verify via API');

            return true;
        }

        $webhookSecret = $this->getConfig('webhook_secret');
        if (! $webhookSecret) {
            throw new InvalidWebhookSignatureException('Webhook secret not configured');
        }

        $parts = [];
        foreach (explode(',', $xSignature) as $part) {
            $segments = explode('=', $part, 2);
            if (count($segments) === 2) {
                $parts[$segments[0]] = $segments[1];
            }
        }

        $timestamp = $parts['ts'] ?? null;
        $receivedSignature = $parts['v1'] ?? null;

        if (! $timestamp || ! $receivedSignature) {
            throw new InvalidWebhookSignatureException('Invalid signature format');
        }

        $dataId = $request->input('data.id');
        $manifest = "id:{$dataId};request-id:{$xRequestId};ts:{$timestamp};";

        $expectedSignature = hash_hmac('sha256', $manifest, $webhookSecret);

        if (! hash_equals($expectedSignature, $receivedSignature)) {
            $this->log('warning', 'Invalid webhook signature', [
                'expected' => $expectedSignature,
                'received' => $receivedSignature,
            ]);

            throw new InvalidWebhookSignatureException(
                message: 'Webhook signature verification failed',
                expectedSignature: $expectedSignature,
                receivedSignature: $receivedSignature,
            );
        }

        $this->log('debug', 'Webhook signature verified successfully');

        return true;
    }

    public function processWebhook(Request $request): WebhookResult
    {
        $payload = $request->all();

        $this->log('info', 'Processing webhook', [
            'type' => $payload['type'] ?? $payload['topic'] ?? 'unknown',
        ]);

        $type = $payload['type'] ?? $payload['topic'] ?? null;
        $dataId = $payload['data']['id'] ?? $payload['id'] ?? null;

        if ($type === 'subscription_authorized_payment') {
            return $this->processSubscriptionChargeWebhook($dataId, $payload);
        }

        if (! in_array($type, ['payment', 'payment.created', 'payment.updated'])) {
            $this->log('debug', 'Ignoring non-payment webhook', ['type' => $type]);

            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'IGNORED_WEBHOOK_TYPE',
                errorMessage: "Webhook type '{$type}' is not a payment notification",
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        if (! $dataId) {
            $this->log('error', 'Missing data ID in webhook payload');

            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'MISSING_DATA_ID',
                errorMessage: 'Missing data ID in webhook payload',
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $this->configureSdk();
        $paymentClient = new PaymentClient;

        try {
            $payment = $paymentClient->get((int) $dataId);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to fetch payment from API', [
                'payment_id' => $dataId,
                'error' => $e->getMessage(),
            ]);

            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'API_ERROR',
                errorMessage: $e->getMessage(),
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $reference = $payment->external_reference ?? null;

        if (! $reference) {
            $this->log('error', 'Missing reference in payment');

            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'MISSING_REFERENCE',
                errorMessage: 'Missing external reference in payment',
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $transaction = $this->findTransactionByReference($reference);

        if (! $transaction) {
            $this->log('warning', 'Transaction not found for reference', [
                'reference' => $reference,
            ]);

            $result = WebhookResult::notFound($reference, $payload);

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        if ($transaction->webhook_received_at && $transaction->isFinal()) {
            $this->log('info', 'Webhook already processed (idempotency)', [
                'transaction_id' => $transaction->id,
            ]);

            $result = WebhookResult::duplicate($transaction, $payload);

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $mpStatus = $payment->status ?? 'pending';
        $status = self::STATUS_MAP[$mpStatus] ?? PaymentStatus::Pending;

        DB::transaction(function () use ($transaction, $status, $payment, $payload) {
            $transaction->update([
                'status' => $status,
                'provider_transaction_id' => (string) $payment->id,
                'webhook_payload' => $payload,
                'webhook_received_at' => now(),
                'webhook_attempts' => $transaction->webhook_attempts + 1,
                'completed_at' => $status->isFinal() ? now() : null,
            ]);
        });

        $this->log('info', 'Webhook processed successfully', [
            'transaction_id' => $transaction->id,
            'status' => $status->value,
        ]);

        $result = WebhookResult::success(
            transaction: $transaction->fresh(),
            status: $status,
            providerTransactionId: (string) $payment->id,
            rawPayload: $payload,
        );

        WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

        if ($status === PaymentStatus::Approved) {
            PaymentApproved::dispatch($transaction->fresh(), $result);
        } elseif (in_array($status, [PaymentStatus::Rejected, PaymentStatus::Voided, PaymentStatus::Refunded])) {
            PaymentRejected::dispatch($transaction->fresh(), $result);
        }

        return $result;
    }

    public function queryStatus(string $transactionId): WebhookResult
    {
        $this->log('info', 'Querying payment status from API', [
            'transaction_id' => $transactionId,
        ]);

        $transaction = PaymentTransaction::find($transactionId);

        if (! $transaction) {
            return WebhookResult::failed(
                transaction: null,
                errorCode: 'TRANSACTION_NOT_FOUND',
                errorMessage: 'Transaction not found',
            );
        }

        if (! $transaction->provider_transaction_id) {
            return WebhookResult::failed(
                transaction: $transaction,
                errorCode: 'NO_PROVIDER_ID',
                errorMessage: 'No provider transaction ID available for query',
            );
        }

        $this->configureSdk();

        try {
            $paymentClient = new PaymentClient;
            $payment = $paymentClient->get((int) $transaction->provider_transaction_id);

            $mpStatus = $payment->status ?? 'pending';
            $status = self::STATUS_MAP[$mpStatus] ?? PaymentStatus::Pending;

            if ($transaction->status !== $status) {
                $transaction->update([
                    'status' => $status,
                    'provider_response' => [
                        'id' => $payment->id,
                        'status' => $payment->status,
                        'status_detail' => $payment->status_detail ?? null,
                    ],
                    'completed_at' => $status->isFinal() ? now() : null,
                ]);

                if ($status === PaymentStatus::Approved) {
                    PaymentApproved::dispatch($transaction->fresh(), WebhookResult::success(
                        transaction: $transaction->fresh(),
                        status: $status,
                        providerTransactionId: (string) $payment->id,
                        rawPayload: ['id' => $payment->id, 'status' => $payment->status],
                    ));
                }
            }

            return WebhookResult::success(
                transaction: $transaction->fresh(),
                status: $status,
                providerTransactionId: (string) $payment->id,
                rawPayload: [
                    'id' => $payment->id,
                    'status' => $payment->status,
                    'status_detail' => $payment->status_detail ?? null,
                ],
            );

        } catch (\Exception $e) {
            $this->log('error', 'Failed to query payment status', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);

            return WebhookResult::failed(
                transaction: $transaction,
                errorCode: 'API_ERROR',
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * MercadoPago fully supports refunds — total or partial — via its API
     * for any approved payment, regardless of payment method.
     *
     * @see https://www.mercadopago.com.co/developers/es/reference/online-payments/checkout-api/refund-order/post
     */
    public function refund(PaymentTransaction $transaction, ?int $amountInCents = null): RefundResult
    {
        if ($transaction->status !== PaymentStatus::Approved) {
            return RefundResult::failed(
                transaction: $transaction,
                errorCode: 'NOT_REFUNDABLE',
                errorMessage: "Cannot refund a transaction with status '{$transaction->status->value}'; only approved payments can be refunded.",
            );
        }

        if (! $transaction->provider_transaction_id) {
            return RefundResult::failed(
                transaction: $transaction,
                errorCode: 'NO_PROVIDER_ID',
                errorMessage: 'No provider transaction ID available to refund.',
            );
        }

        $this->log('info', 'Refunding payment', [
            'transaction_id' => $transaction->id,
            'amount_in_cents' => $amountInCents,
        ]);

        $this->configureSdk();
        $refundClient = new PaymentRefundClient;
        $paymentId = (int) $transaction->provider_transaction_id;

        try {
            $refund = $amountInCents !== null
                ? $refundClient->refund($paymentId, $amountInCents / 100)
                : $refundClient->refundTotal($paymentId);

            $refundedAmountInCents = $amountInCents ?? $transaction->amount;

            $transaction->update([
                'status' => PaymentStatus::Refunded,
                'refunded_amount' => $refundedAmountInCents,
                'refunded_at' => now(),
                'provider_refund_id' => (string) $refund->id,
            ]);

            $this->log('info', 'Payment refunded successfully', [
                'transaction_id' => $transaction->id,
                'refund_id' => $refund->id,
            ]);

            $result = RefundResult::success(
                transaction: $transaction->fresh(),
                refundedAmountInCents: $refundedAmountInCents,
                providerRefundId: (string) $refund->id,
            );

            PaymentRefunded::dispatch($transaction->fresh(), $result);

            return $result;

        } catch (\Exception $e) {
            $this->log('error', 'Failed to refund payment', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return RefundResult::failed(
                transaction: $transaction,
                errorCode: 'API_ERROR',
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * MercadoPago has a full native recurring-billing engine ("Suscripciones"
     * / PreApproval Plans) — it bills each cycle automatically once a
     * subscription is authorized.
     *
     * @see https://www.mercadopago.com.co/developers/es/docs/subscriptions/overview
     */
    public function createPlan(PlanData $data): PlanResult
    {
        $this->configureSdk();
        $planClient = new PreApprovalPlanClient;

        try {
            $mpPlan = $planClient->create([
                'reason' => $data->name,
                'auto_recurring' => $this->toAutoRecurring($data->interval, $data->intervalCount, $data->amount, $data->currency, $data->trialDays),
                'back_url' => config('payments.urls.return'),
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create subscription plan', ['error' => $e->getMessage()]);

            return PlanResult::failed(null, 'API_ERROR', $e->getMessage());
        }

        $plan = SubscriptionPlan::create([
            'provider' => PaymentProvider::MercadoPago,
            'provider_plan_id' => $mpPlan->id,
            'name' => $data->name,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'interval' => $data->interval,
            'interval_count' => $data->intervalCount,
            'trial_days' => $data->trialDays,
            'metadata' => $data->metadata,
        ]);

        return PlanResult::success($plan);
    }

    /**
     * Subscribes a customer via MercadoPago's `preapproval` object, using an
     * already-tokenized card ($data->paymentToken = card_token_id) so the
     * subscription is authorized immediately without a checkout redirect.
     */
    public function createSubscription(SubscriptionData $data): SubscriptionResult
    {
        if (! $data->plan->provider_plan_id) {
            return SubscriptionResult::failed(
                subscription: null,
                errorCode: 'MISSING_PROVIDER_PLAN',
                errorMessage: 'This plan was not created via the mercadopago driver (no provider_plan_id) — call createPlan() with it first.',
            );
        }

        $this->configureSdk();
        $preApprovalClient = new PreApprovalClient;

        try {
            $preapproval = $preApprovalClient->create([
                'preapproval_plan_id' => $data->plan->provider_plan_id,
                'payer_email' => $data->getCustomerEmail(),
                'card_token_id' => $data->paymentToken,
                'external_reference' => $data->referenceId,
                'back_url' => config('payments.urls.return'),
                'status' => 'authorized',
            ]);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to create subscription', ['error' => $e->getMessage()]);

            return SubscriptionResult::failed(null, 'API_ERROR', $e->getMessage());
        }

        $subscription = Subscription::create([
            'subscription_plan_id' => $data->plan->id,
            'reference_id' => $data->referenceId,
            'provider' => PaymentProvider::MercadoPago,
            'provider_subscription_id' => $preapproval->id,
            'customer_email' => $data->getCustomerEmail(),
            'customer_name' => $data->getCustomerName(),
            'customer_phone' => $data->getCustomerPhone(),
            'status' => $this->mapPreapprovalStatus($preapproval->status),
            'next_billing_date' => $preapproval->next_payment_date ? \Illuminate\Support\Carbon::parse($preapproval->next_payment_date) : null,
            'started_at' => now(),
            'metadata' => $data->metadata,
            'provider_response' => ['id' => $preapproval->id, 'status' => $preapproval->status],
        ]);

        $result = SubscriptionResult::success($subscription);

        SubscriptionCreated::dispatch($subscription, $result);

        return $result;
    }

    public function cancelSubscription(Subscription $subscription): SubscriptionResult
    {
        if (! $subscription->provider_subscription_id) {
            return SubscriptionResult::failed(
                subscription: $subscription,
                errorCode: 'NO_PROVIDER_ID',
                errorMessage: 'No MercadoPago preapproval id stored for this subscription.',
            );
        }

        $this->configureSdk();
        $preApprovalClient = new PreApprovalClient;

        try {
            $preApprovalClient->update($subscription->provider_subscription_id, ['status' => 'cancelled']);
        } catch (\Exception $e) {
            $this->log('error', 'Failed to cancel subscription', ['error' => $e->getMessage()]);

            return SubscriptionResult::failed($subscription, 'API_ERROR', $e->getMessage());
        }

        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => now(),
            'next_billing_date' => null,
        ]);

        $result = SubscriptionResult::success($subscription->fresh());

        SubscriptionCancelled::dispatch($subscription->fresh(), $result);

        return $result;
    }

    /**
     * MercadoPago bills subscriptions itself — there is no "charge this
     * cycle now" endpoint in its API/SDK. This is not part of this
     * package's own scheduler for MercadoPago (see
     * config('payments.subscriptions.scheduled_providers')); recurring
     * charges arrive via processWebhook()'s `subscription_authorized_payment`
     * handling instead.
     */
    public function chargeSubscriptionCycle(Subscription $subscription): PaymentResult
    {
        return PaymentResult::failed(
            errorCode: 'NOT_APPLICABLE',
            errorMessage: 'MercadoPago bills subscriptions automatically via its own recurring engine — this '
            .'method is not used for MercadoPago. Recurring charge results arrive via processWebhook() '
            ."(type='subscription_authorized_payment'). Do not add 'mercadopago' to "
            .'config(payments.subscriptions.scheduled_providers) or you risk double-charging customers.',
        );
    }

    /**
     * Handles MercadoPago's `subscription_authorized_payment` webhook — a
     * recurring cycle charge billed automatically by MercadoPago's own
     * engine. Creates a PaymentTransaction tagged with the matching
     * Subscription, reusing the same infrastructure as one-shot payments.
     *
     * Best-effort: the exact webhook type/payload shape for subscription
     * charges was not verified against a live MercadoPago sandbox — verify
     * end-to-end before relying on it in production.
     */
    protected function processSubscriptionChargeWebhook(?string $paymentId, array $payload): WebhookResult
    {
        if (! $paymentId) {
            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'MISSING_DATA_ID',
                errorMessage: 'Missing data ID in subscription payment webhook payload',
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $this->configureSdk();
        $paymentClient = new PaymentClient;

        try {
            $payment = $paymentClient->get((int) $paymentId);
        } catch (\Exception $e) {
            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'API_ERROR',
                errorMessage: $e->getMessage(),
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $preapprovalId = $payload['data']['preapproval_id'] ?? null;

        $subscription = $preapprovalId
            ? Subscription::where('provider_subscription_id', $preapprovalId)->first()
            : Subscription::where('reference_id', $payment->external_reference ?? '__none__')->first();

        if (! $subscription) {
            $result = WebhookResult::notFound((string) ($preapprovalId ?? $payment->external_reference ?? $paymentId), $payload);

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $existing = PaymentTransaction::where('provider_transaction_id', (string) $payment->id)->first();

        if ($existing) {
            $result = WebhookResult::duplicate($existing, $payload);

            WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

            return $result;
        }

        $status = self::STATUS_MAP[$payment->status ?? 'pending'] ?? PaymentStatus::Pending;

        $transaction = PaymentTransaction::create([
            'subscription_id' => $subscription->id,
            'reference_id' => $subscription->reference_id.'-CYCLE-'.now()->format('YmdHis'),
            'provider' => PaymentProvider::MercadoPago,
            'provider_transaction_id' => (string) $payment->id,
            'amount' => (int) round(($payment->transaction_amount ?? 0) * 100),
            'currency' => $payment->currency_id ?? $subscription->plan->currency,
            'status' => $status,
            'idempotency_key' => (string) Str::uuid(),
            'webhook_payload' => $payload,
            'webhook_received_at' => now(),
            'webhook_attempts' => 1,
            'initiated_at' => now(),
            'completed_at' => $status->isFinal() ? now() : null,
        ]);

        $subscription->update([
            'last_charged_at' => now(),
            'failed_charge_attempts' => $status === PaymentStatus::Approved ? 0 : $subscription->failed_charge_attempts + 1,
        ]);

        $result = WebhookResult::success(
            transaction: $transaction,
            status: $status,
            providerTransactionId: (string) $payment->id,
            rawPayload: $payload,
        );

        WebhookReceived::dispatch(PaymentProvider::MercadoPago, $result, $payload);

        $paymentResult = PaymentResult::success(
            transaction: $transaction,
            provider: PaymentProvider::MercadoPago,
            widgetUrl: $this->getWidgetUrl(),
            publicKey: $this->getPublicKey() ?? $this->getConfig('access_token'),
            amountInCents: $transaction->amount,
            currency: $transaction->currency,
            reference: $transaction->reference_id,
            signature: (string) $payment->id,
        );

        if ($status === PaymentStatus::Approved) {
            SubscriptionChargeSucceeded::dispatch($subscription->fresh(), $paymentResult);
        } else {
            SubscriptionChargeFailed::dispatch($subscription->fresh(), $paymentResult);
        }

        return $result;
    }

    /**
     * Map a plan's interval/count to MercadoPago's auto_recurring shape.
     * MercadoPago's frequency_type only supports 'days' and 'months', so
     * weeks/years are expressed as multiples of those.
     */
    protected function toAutoRecurring(BillingInterval $interval, int $count, int $amount, string $currency, ?int $trialDays): array
    {
        [$frequency, $frequencyType] = match ($interval) {
            BillingInterval::Day => [$count, 'days'],
            BillingInterval::Week => [$count * 7, 'days'],
            BillingInterval::Month => [$count, 'months'],
            BillingInterval::Year => [$count * 12, 'months'],
        };

        $autoRecurring = [
            'frequency' => $frequency,
            'frequency_type' => $frequencyType,
            'transaction_amount' => $amount / 100,
            'currency_id' => $currency,
        ];

        if ($trialDays) {
            $autoRecurring['free_trial'] = [
                'frequency' => $trialDays,
                'frequency_type' => 'days',
            ];
        }

        return $autoRecurring;
    }

    /**
     * Map a MercadoPago preapproval status to our SubscriptionStatus.
     */
    protected function mapPreapprovalStatus(?string $status): SubscriptionStatus
    {
        return match ($status) {
            'authorized' => SubscriptionStatus::Active,
            'paused' => SubscriptionStatus::PastDue,
            'cancelled' => SubscriptionStatus::Cancelled,
            default => SubscriptionStatus::Trialing,
        };
    }

    /**
     * Configure the MercadoPago SDK.
     */
    protected function configureSdk(): void
    {
        $accessToken = $this->getConfig('access_token');

        if (! $accessToken) {
            throw new \RuntimeException('MercadoPago access token not configured');
        }

        MercadoPagoConfig::setAccessToken($accessToken);

        if ($this->isSandbox()) {
            MercadoPagoConfig::setRuntimeEnviroment(MercadoPagoConfig::LOCAL);
        }
    }

    /**
     * Build items array for MercadoPago preference.
     */
    protected function buildItems(PaymentData $paymentData): array
    {
        if (! empty($paymentData->items)) {
            $items = [];
            foreach ($paymentData->items as $item) {
                $items[] = [
                    'id' => (string) ($item['id'] ?? Str::uuid()),
                    'title' => $item['title'] ?? 'Item',
                    'description' => $item['description'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => ($item['unit_price'] ?? $paymentData->amount) / 100,
                    'currency_id' => $paymentData->currency,
                ];
            }

            return $items;
        }

        return [
            [
                'id' => $paymentData->referenceId,
                'title' => $paymentData->description ?? 'Payment',
                'quantity' => 1,
                'unit_price' => $paymentData->amount / 100,
                'currency_id' => $paymentData->currency,
            ],
        ];
    }

    /**
     * Find a transaction by its reference.
     */
    protected function findTransactionByReference(string $reference): ?PaymentTransaction
    {
        $parsed = $this->parseReference($reference);

        if ($parsed['transaction_id']) {
            return PaymentTransaction::find($parsed['transaction_id']);
        }

        return null;
    }
}
