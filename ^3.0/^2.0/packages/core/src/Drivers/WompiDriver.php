<?php

declare(strict_types=1);

namespace Korbytes\Payments\Drivers;

use Korbytes\Payments\Contracts\Records\PayoutRecord;
use Korbytes\Payments\Contracts\Records\SubscriptionRecord;
use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\Http\WebhookRequest;
use Korbytes\Payments\Contracts\PayoutDriverInterface;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\DTOs\PayoutBeneficiaryData;
use Korbytes\Payments\DTOs\PayoutBeneficiaryResult;
use Korbytes\Payments\DTOs\PayoutData;
use Korbytes\Payments\DTOs\PayoutResult;
use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\PlanResult;
use Korbytes\Payments\DTOs\RefundResult;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\DTOs\SubscriptionResult;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Enums\PayoutStatus;
use Korbytes\Payments\Enums\SubscriptionStatus;
use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\Core\Events\PaymentCreated;
use Korbytes\Payments\Core\Events\PaymentRefunded;
use Korbytes\Payments\Core\Events\PaymentRejected;
use Korbytes\Payments\Core\Events\SubscriptionCancelled;
use Korbytes\Payments\Core\Events\SubscriptionChargeFailed;
use Korbytes\Payments\Core\Events\SubscriptionChargeSucceeded;
use Korbytes\Payments\Core\Events\SubscriptionCreated;
use Korbytes\Payments\Core\Events\WebhookReceived;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;

/**
 * Wompi Payment Driver Implementation.
 *
 * @see https://docs.wompi.co/docs/colombia/widget-checkout-web/
 * @see https://docs.wompi.co/docs/colombia/eventos/
 */
class WompiDriver extends AbstractDriver implements PayoutDriverInterface
{
    /**
     * Wompi Payouts transaction/batch statuses (from its OpenAPI spec) mapped
     * to our own PayoutStatus.
     *
     * @see https://api.swaggerhub.com/apis/wompi/Payouts/1.0.0
     */
    protected const array PAYOUT_STATUS_MAP = [
        'PENDING' => PayoutStatus::Pending,
        'READY_TO_FILE' => PayoutStatus::Pending,
        'ADDED_TO_FILE' => PayoutStatus::Pending,
        'UNKNOWN' => PayoutStatus::Pending,
        'PROCESSING' => PayoutStatus::Processing,
        'APPROVED' => PayoutStatus::Completed,
        'CANCELLED' => PayoutStatus::Rejected,
        'REJECTED' => PayoutStatus::Rejected,
        'FAILED' => PayoutStatus::Failed,
    ];

    /**
     * Map Wompi transaction statuses to internal statuses.
     */
    protected const array STATUS_MAP = [
        'PENDING' => PaymentStatus::Pending,
        'APPROVED' => PaymentStatus::Approved,
        'DECLINED' => PaymentStatus::Rejected,
        'VOIDED' => PaymentStatus::Voided,
        'ERROR' => PaymentStatus::Rejected,
    ];

    public function getName(): string
    {
        return PaymentProvider::Wompi->value;
    }

    public function getWidgetUrl(): string
    {
        return $this->getConfig('widget_url', 'https://checkout.wompi.co/widget.js');
    }

    public function getPublicKey(): ?string
    {
        return $this->getConfig('public_key');
    }

    public function getBaseUrl(): string
    {
        $urls = $this->getConfig('base_url', [
            'sandbox' => 'https://sandbox.wompi.co/v1',
            'production' => 'https://production.wompi.co/v1',
        ]);

        if (is_string($urls)) {
            return $urls;
        }

        return $this->isSandbox() ? $urls['sandbox'] : $urls['production'];
    }

    public function charge(PaymentData $paymentData): PaymentResult
    {
        $this->log('info', 'Creating payment intent', [
            'reference_id' => $paymentData->referenceId,
            'amount' => $paymentData->amount,
        ]);

        // Create transaction record
        $transaction = $this->ctx->runner->run(function () use ($paymentData) {
            return $this->ctx->transactions->create([
                'reference_id' => $paymentData->referenceId,
                'provider' => PaymentProvider::Wompi,
                'amount' => $paymentData->amount,
                'currency' => $paymentData->currency,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => $this->uuid(),
                'metadata' => $paymentData->metadata,
                'initiated_at' => $this->now(),
            ]);
        });

        $reference = $this->generateReference($paymentData->referenceId, $transaction->id);
        $signature = $this->generateIntegritySignature(
            reference: $reference,
            amountInCents: $paymentData->amount,
            currency: $paymentData->currency,
        );

        $redirectUrl = $paymentData->returnUrl ?? $this->setting('urls.return');

        $this->log('info', 'Payment intent created', [
            'transaction_id' => $transaction->id,
            'reference' => $reference,
        ]);

        $result = PaymentResult::success(
            transaction: $transaction,
            provider: PaymentProvider::Wompi,
            widgetUrl: $this->getWidgetUrl(),
            publicKey: $this->getPublicKey(),
            amountInCents: $paymentData->amount,
            currency: $paymentData->currency,
            reference: $reference,
            signature: $signature,
            redirectUrl: $redirectUrl,
            extra: [
                'customer_email' => $paymentData->getCustomerEmail(),
                'customer_name' => $paymentData->getCustomerName(),
                'customer_phone' => $paymentData->getCustomerPhone(),
            ],
        );

        $this->emit(PaymentCreated::class, $transaction, $result);

        return $result;
    }

    public function verifyWebhookSignature(object $request): bool
    {
        $request = WebhookRequest::from($request);

        $payload = $request->all();

        $this->log('debug', 'Verifying webhook signature', [
            'event' => $payload['event'] ?? 'unknown',
        ]);

        $signature = $payload['signature'] ?? null;
        if (! $signature) {
            throw new InvalidWebhookSignatureException('Missing signature in webhook payload');
        }

        $properties = $signature['properties'] ?? [];
        $timestamp = $signature['timestamp'] ?? null;
        $checksum = $signature['checksum'] ?? null;

        if (! $properties || ! $timestamp || ! $checksum) {
            throw new InvalidWebhookSignatureException('Incomplete signature data');
        }

        $data = $payload['data']['transaction'] ?? $payload['data'] ?? [];
        $stringToHash = '';

        foreach ($properties as $property) {
            $value = \Korbytes\Payments\Support\Arr::get($data, $property, '');
            $stringToHash .= $value;
        }

        $stringToHash .= $timestamp;
        $stringToHash .= $this->getConfig('events_secret');

        $expectedChecksum = hash('sha256', $stringToHash);

        if (! hash_equals($expectedChecksum, $checksum)) {
            $this->log('warning', 'Invalid webhook signature', [
                'expected' => $expectedChecksum,
                'received' => $checksum,
            ]);

            throw new InvalidWebhookSignatureException(
                message: 'Webhook signature verification failed',
                expectedSignature: $expectedChecksum,
                receivedSignature: $checksum,
            );
        }

        $this->log('debug', 'Webhook signature verified successfully');

        return true;
    }

    public function processWebhook(object $request): WebhookResult
    {
        $request = WebhookRequest::from($request);

        $payload = $request->all();

        $this->log('info', 'Processing webhook', [
            'event' => $payload['event'] ?? 'unknown',
        ]);

        $transactionData = $payload['data']['transaction'] ?? $payload['data'] ?? [];
        $reference = $transactionData['reference'] ?? null;
        $providerTransactionId = $transactionData['id'] ?? null;
        $wompiStatus = $transactionData['status'] ?? null;

        if (! $reference) {
            $this->log('error', 'Missing reference in webhook payload');

            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'MISSING_REFERENCE',
                errorMessage: 'Missing reference in webhook payload',
                rawPayload: $payload,
            );

            $this->emit(WebhookReceived::class, PaymentProvider::Wompi, $result, $payload);

            return $result;
        }

        $transaction = $this->findTransactionByReference($reference);

        if (! $transaction) {
            $this->log('warning', 'Transaction not found for reference', [
                'reference' => $reference,
            ]);

            $result = WebhookResult::notFound($reference, $payload);

            $this->emit(WebhookReceived::class, PaymentProvider::Wompi, $result, $payload);

            return $result;
        }

        // Check for idempotency
        if ($transaction->webhook_received_at && $transaction->isFinal()) {
            $this->log('info', 'Webhook already processed (idempotency)', [
                'transaction_id' => $transaction->id,
            ]);

            $result = WebhookResult::duplicate($transaction, $payload);

            $this->emit(WebhookReceived::class, PaymentProvider::Wompi, $result, $payload);

            return $result;
        }

        $status = self::STATUS_MAP[$wompiStatus] ?? PaymentStatus::Pending;

        // Update transaction
        $this->ctx->runner->run(function () use ($transaction, $status, $providerTransactionId, $payload) {
            $transaction->update([
                'status' => $status,
                'provider_transaction_id' => $providerTransactionId,
                'webhook_payload' => $payload,
                'webhook_received_at' => $this->now(),
                'webhook_attempts' => $transaction->webhook_attempts + 1,
                'completed_at' => $status->isFinal() ? $this->now() : null,
            ]);
        });

        $this->log('info', 'Webhook processed successfully', [
            'transaction_id' => $transaction->id,
            'status' => $status->value,
        ]);

        $result = WebhookResult::success(
            transaction: $transaction->fresh(),
            status: $status,
            providerTransactionId: $providerTransactionId,
            rawPayload: $payload,
        );

        $this->emit(WebhookReceived::class, PaymentProvider::Wompi, $result, $payload);

        // Dispatch status-specific events
        if ($status === PaymentStatus::Approved) {
            $this->emit(PaymentApproved::class, $transaction->fresh(), $result);
        } elseif (in_array($status, [PaymentStatus::Rejected, PaymentStatus::Voided])) {
            $this->emit(PaymentRejected::class, $transaction->fresh(), $result);
        }

        return $result;
    }

    public function queryStatus(string $transactionId): WebhookResult
    {
        $this->log('info', 'Querying payment status from API', [
            'transaction_id' => $transactionId,
        ]);

        $transaction = $this->ctx->transactions->find($transactionId);

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

        try {
            $response = $this->makeRequest(
                method: 'GET',
                endpoint: "/transactions/{$transaction->provider_transaction_id}",
                headers: [
                    'Authorization' => 'Bearer '.$this->getConfig('private_key'),
                ],
            );

            $data = $response['data'] ?? [];
            $wompiStatus = $data['status'] ?? null;
            $status = self::STATUS_MAP[$wompiStatus] ?? PaymentStatus::Pending;

            if ($transaction->status !== $status) {
                $transaction->update([
                    'status' => $status,
                    'provider_response' => $response,
                    'completed_at' => $status->isFinal() ? $this->now() : null,
                ]);

                if ($status === PaymentStatus::Approved) {
                    $this->emit(PaymentApproved::class, $transaction->fresh(), WebhookResult::success(
                        transaction: $transaction->fresh(),
                        status: $status,
                        providerTransactionId: $data['id'] ?? null,
                        rawPayload: $response,
                    ));
                }
            }

            return WebhookResult::success(
                transaction: $transaction->fresh(),
                status: $status,
                providerTransactionId: $data['id'] ?? null,
                rawPayload: $response,
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
     * Wompi does not expose a post-settlement refund API. The only reversal
     * mechanism it offers is voiding a card transaction that hasn't been
     * fully settled yet (certain pre-capture statuses only), and it's
     * all-or-nothing — no partial amounts.
     *
     * If the void succeeds, the transaction is marked Voided (no money was
     * actually captured, so "refunded" would be misleading). If Wompi
     * rejects the void — most commonly because the transaction already
     * settled — this returns a failed result telling the caller to refund
     * manually via the Wompi dashboard.
     *
     * @see https://docs.wompi.co/en/docs/colombia/transacciones/
     */
    public function refund(TransactionRecord $transaction, ?int $amountInCents = null): RefundResult
    {
        if ($amountInCents !== null && $amountInCents !== $transaction->amount) {
            return RefundResult::notSupported(
                $transaction,
                'Wompi does not support partial refunds via API — voiding only cancels the full transaction, '
                .'and only before it settles. Refund the difference manually via the Wompi dashboard.',
            );
        }

        if (! $transaction->provider_transaction_id) {
            return RefundResult::failed(
                transaction: $transaction,
                errorCode: 'NO_PROVIDER_ID',
                errorMessage: 'No provider transaction ID available to void.',
            );
        }

        $this->log('info', 'Attempting to void transaction (Wompi has no post-settlement refund API)', [
            'transaction_id' => $transaction->id,
        ]);

        try {
            $response = $this->makeRequest(
                method: 'POST',
                endpoint: "/transactions/{$transaction->provider_transaction_id}/void",
                headers: [
                    'Authorization' => 'Bearer '.$this->getConfig('private_key'),
                ],
            );

            $data = $response['data'] ?? [];

            if (($data['status'] ?? null) !== 'VOIDED') {
                return RefundResult::failed(
                    transaction: $transaction,
                    errorCode: 'MANUAL_REFUND_REQUIRED',
                    errorMessage: 'Wompi did not confirm the void. The transaction may already be settled and can only be refunded manually via the Wompi dashboard.',
                    rawPayload: $response,
                );
            }

            $transaction->update([
                'status' => PaymentStatus::Voided,
                'refunded_amount' => $transaction->amount,
                'refunded_at' => $this->now(),
                'provider_response' => $response,
            ]);

            $this->log('info', 'Transaction voided successfully', [
                'transaction_id' => $transaction->id,
            ]);

            $result = RefundResult::success(
                transaction: $transaction->fresh(),
                refundedAmountInCents: $transaction->amount,
                providerRefundId: $data['id'] ?? $transaction->provider_transaction_id,
                rawPayload: $response,
            );

            $this->emit(PaymentRefunded::class, $transaction->fresh(), $result);

            return $result;

        } catch (\Exception $e) {
            $this->log('error', 'Failed to void transaction', [
                'transaction_id' => $transaction->id,
                'error' => $e->getMessage(),
            ]);

            return RefundResult::failed(
                transaction: $transaction,
                errorCode: 'MANUAL_REFUND_REQUIRED',
                errorMessage: 'Wompi void request failed ('.$e->getMessage().'). This transaction likely can only be refunded manually via the Wompi dashboard.',
            );
        }
    }

    /**
     * Wompi has no server-side plan object — it only offers tokenized
     * "payment sources" for merchant-initiated recurring charges. The plan
     * is purely a local record used to compute billing cycles.
     *
     * @see https://docs.wompi.co/en/docs/colombia/fuentes-de-pago/
     */
    public function createPlan(PlanData $data): PlanResult
    {
        $plan = $this->ctx->subscriptions->createPlan([
            'provider' => PaymentProvider::Wompi,
            'provider_plan_id' => null,
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
     * Subscribing a customer means creating a Wompi "payment source" from an
     * already-tokenized card, which lets us charge it later without the
     * customer present (`recurrent: true` transactions). Requires an
     * `acceptance_token` in $data->providerOptions — obtained from
     * `GET /merchants/{public_key}` and shown to the customer as part of
     * accepting Wompi's terms at tokenization time.
     */
    public function createSubscription(SubscriptionData $data): SubscriptionResult
    {
        $acceptanceToken = $data->providerOptions['acceptance_token'] ?? null;

        if (! $acceptanceToken) {
            return SubscriptionResult::failed(
                subscription: null,
                errorCode: 'MISSING_ACCEPTANCE_TOKEN',
                errorMessage: 'Wompi requires an acceptance_token in providerOptions to create a payment source '
                .'(fetch it from GET /merchants/{public_key} and have the customer accept it).',
            );
        }

        try {
            $response = $this->makeRequest(
                method: 'POST',
                endpoint: '/payment_sources',
                data: [
                    'type' => 'CARD',
                    'token' => $data->paymentToken,
                    'customer_email' => $data->getCustomerEmail(),
                    'acceptance_token' => $acceptanceToken,
                ],
                headers: [
                    'Authorization' => 'Bearer '.$this->getConfig('private_key'),
                ],
            );
        } catch (\Exception $e) {
            return SubscriptionResult::failed(
                subscription: null,
                errorCode: 'PAYMENT_SOURCE_ERROR',
                errorMessage: $e->getMessage(),
            );
        }

        $paymentSourceId = $response['data']['id'] ?? null;

        if (! $paymentSourceId) {
            return SubscriptionResult::failed(
                subscription: null,
                errorCode: 'PAYMENT_SOURCE_ERROR',
                errorMessage: 'Wompi did not return a payment source id.',
                rawPayload: $response,
            );
        }

        $subscription = $this->ctx->subscriptions->create([
            'subscription_plan_id' => $data->plan->id,
            'reference_id' => $data->referenceId,
            'provider' => PaymentProvider::Wompi,
            'provider_payment_source_id' => (string) $paymentSourceId,
            'customer_email' => $data->getCustomerEmail(),
            'customer_name' => $data->getCustomerName(),
            'customer_phone' => $data->getCustomerPhone(),
            'status' => SubscriptionStatus::Active,
            'next_billing_date' => $data->plan->interval->addTo($this->now(), $data->plan->interval_count),
            'started_at' => $this->now(),
            'metadata' => $data->metadata,
            'provider_response' => $response,
        ]);

        $this->log('info', 'Subscription created', [
            'subscription_id' => $subscription->id,
            'payment_source_id' => $paymentSourceId,
        ]);

        $result = SubscriptionResult::success($subscription, $response);

        $this->emit(SubscriptionCreated::class, $subscription, $result);

        return $result;
    }

    /**
     * Wompi has no server-side subscription object to cancel — this only
     * stops our own scheduler from billing it. The stored payment source
     * token itself is not deleted from Wompi (no confirmed delete endpoint).
     */
    public function cancelSubscription(SubscriptionRecord $subscription): SubscriptionResult
    {
        $subscription->update([
            'status' => SubscriptionStatus::Cancelled,
            'cancelled_at' => $this->now(),
            'next_billing_date' => null,
        ]);

        $result = SubscriptionResult::success($subscription->fresh());

        $this->emit(SubscriptionCancelled::class, $subscription->fresh(), $result);

        return $result;
    }

    /**
     * Charge one billing cycle using the stored payment source. This is
     * what `payments:process-subscriptions` calls for Wompi subscriptions
     * (see config('payments.subscriptions.scheduled_providers')).
     */
    public function chargeSubscriptionCycle(SubscriptionRecord $subscription): PaymentResult
    {
        if (! $subscription->provider_payment_source_id) {
            return PaymentResult::failed(
                errorCode: 'NO_PAYMENT_SOURCE',
                errorMessage: 'No Wompi payment source stored for this subscription.',
            );
        }

        $plan = $subscription->plan;
        $cycleReferenceId = $subscription->reference_id.'-CYCLE-'.$this->now()->format('YmdHis');

        $transaction = $this->ctx->transactions->create([
            'subscription_id' => $subscription->id,
            'reference_id' => $cycleReferenceId,
            'provider' => PaymentProvider::Wompi,
            'amount' => $plan->amount,
            'currency' => $plan->currency,
            'status' => PaymentStatus::Pending,
            'idempotency_key' => $this->uuid(),
            'initiated_at' => $this->now(),
        ]);

        $reference = $this->generateReference($cycleReferenceId, $transaction->id);

        try {
            $response = $this->makeRequest(
                method: 'POST',
                endpoint: '/transactions',
                data: [
                    'amount_in_cents' => $plan->amount,
                    'currency' => $plan->currency,
                    'customer_email' => $subscription->customer_email,
                    'payment_source_id' => (int) $subscription->provider_payment_source_id,
                    'reference' => $reference,
                    'recurrent' => true,
                ],
                headers: [
                    'Authorization' => 'Bearer '.$this->getConfig('private_key'),
                ],
            );
        } catch (\Exception $e) {
            $transaction->update(['status' => PaymentStatus::Rejected, 'error_message' => $e->getMessage()]);

            $result = PaymentResult::failed(errorCode: 'API_ERROR', errorMessage: $e->getMessage(), transaction: $transaction->fresh());

            $subscription->update(['failed_charge_attempts' => $subscription->failed_charge_attempts + 1]);

            $this->emit(SubscriptionChargeFailed::class, $subscription->fresh(), $result);

            return $result;
        }

        $data = $response['data'] ?? [];
        $wompiStatus = $data['status'] ?? null;
        $status = self::STATUS_MAP[$wompiStatus] ?? PaymentStatus::Pending;

        $transaction->update([
            'status' => $status,
            'provider_transaction_id' => $data['id'] ?? null,
            'provider_response' => $response,
            'completed_at' => $status->isFinal() ? $this->now() : null,
        ]);

        $subscription->update([
            'last_charged_at' => $this->now(),
            'next_billing_date' => $plan->interval->addTo($this->now(), $plan->interval_count),
            'failed_charge_attempts' => $status === PaymentStatus::Approved ? 0 : $subscription->failed_charge_attempts + 1,
        ]);

        $result = PaymentResult::success(
            transaction: $transaction->fresh(),
            provider: PaymentProvider::Wompi,
            widgetUrl: $this->getWidgetUrl(),
            publicKey: $this->getPublicKey(),
            amountInCents: $plan->amount,
            currency: $plan->currency,
            reference: $reference,
            signature: '',
        );

        if ($status === PaymentStatus::Approved) {
            $this->emit(SubscriptionChargeSucceeded::class, $subscription->fresh(), $result);
        } else {
            $this->emit(SubscriptionChargeFailed::class, $subscription->fresh(), $result);
        }

        return $result;
    }

    /**
     * Wompi's Payouts API has no separate beneficiary-registration
     * endpoint — bank details are sent inline with each payout transaction
     * instead. This just keeps a local, reusable record so callers don't
     * have to re-enter bank details for every payout.
     *
     * @see https://api.swaggerhub.com/apis/wompi/Payouts/1.0.0
     */
    public function registerBeneficiary(PayoutBeneficiaryData $data): PayoutBeneficiaryResult
    {
        $beneficiary = $this->ctx->payouts->createBeneficiary([
            'provider' => PaymentProvider::Wompi,
            'provider_beneficiary_id' => null,
            'name' => $data->name,
            'legal_id_type' => $data->legalIdType,
            'legal_id' => $data->legalId,
            'person_type' => $data->personType,
            'bank_code' => $data->bankCode,
            'account_type' => $data->accountType,
            'account_number' => $data->accountNumber,
            'category' => $data->category,
            'email' => $data->email,
            'phone' => $data->phone,
            'metadata' => $data->metadata,
        ]);

        return PayoutBeneficiaryResult::success($beneficiary);
    }

    /**
     * Send an immediate payout via `POST /payouts`. Requires a funding
     * `account_id` configured in payments.payouts.wompi.account_id (see
     * `GET /accounts` on the Payouts API).
     */
    public function createPayout(PayoutData $data): PayoutResult
    {
        $accountId = $this->getPayoutConfig('account_id');

        if (! $accountId) {
            return PayoutResult::failed(
                payout: null,
                errorCode: 'MISSING_ACCOUNT_ID',
                errorMessage: 'Wompi payouts require a funding account_id (see GET /accounts on the Payouts API), '
                .'configured in payments.payouts.wompi.account_id.',
            );
        }

        $beneficiary = $data->beneficiary;

        $payout = $this->ctx->payouts->create([
            'payout_beneficiary_id' => $beneficiary->id,
            'reference_id' => $data->referenceId,
            'provider' => PaymentProvider::Wompi,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'status' => PayoutStatus::Pending,
            'description' => $data->description,
            'metadata' => $data->metadata,
        ]);

        $paymentType = match ($beneficiary->category) {
            'payroll' => 'PAYROLL',
            default => 'PROVIDERS',
        };

        try {
            $response = $this->ctx->http->withHeaders($this->payoutHeaders())
                ->timeout(30)
                ->post($this->getPayoutBaseUrl().'/payouts', [
                    'reference' => $data->referenceId,
                    'accountId' => $accountId,
                    'paymentType' => $paymentType,
                    'transactions' => [[
                        'legalIdType' => $beneficiary->legal_id_type,
                        'legalId' => $beneficiary->legal_id,
                        'bankId' => $beneficiary->bank_code,
                        'accountType' => $beneficiary->account_type,
                        'accountNumber' => $beneficiary->account_number,
                        'name' => $beneficiary->name,
                        'amount' => $data->amount,
                        'personType' => $beneficiary->person_type,
                        'description' => $data->description,
                        'email' => $beneficiary->email,
                        'phone' => $beneficiary->phone,
                        'reference' => $data->referenceId,
                    ]],
                ]);
        } catch (\Exception $e) {
            $payout->update(['status' => PayoutStatus::Failed]);

            return PayoutResult::failed($payout->fresh(), 'API_ERROR', $e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->failed()) {
            $payout->update(['status' => PayoutStatus::Failed, 'provider_response' => $body]);

            return PayoutResult::failed(
                payout: $payout->fresh(),
                errorCode: 'API_ERROR',
                errorMessage: $body['message'] ?? 'Wompi payout creation failed.',
                rawPayload: $body,
            );
        }

        $payout->update([
            'provider_payout_id' => $body['data']['payoutId'] ?? null,
            'status' => PayoutStatus::Processing,
            'provider_response' => $body,
        ]);

        return PayoutResult::success($payout->fresh(), $body);
    }

    public function queryPayoutStatus(PayoutRecord $payout): PayoutResult
    {
        if (! $payout->provider_payout_id) {
            return PayoutResult::failed(
                payout: $payout,
                errorCode: 'NO_PROVIDER_ID',
                errorMessage: 'No Wompi payout id available for query.',
            );
        }

        try {
            $response = $this->ctx->http->withHeaders($this->payoutHeaders())
                ->timeout(30)
                ->get($this->getPayoutBaseUrl()."/payouts/{$payout->provider_payout_id}");
        } catch (\Exception $e) {
            return PayoutResult::failed($payout, 'API_ERROR', $e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->failed()) {
            return PayoutResult::failed(
                payout: $payout,
                errorCode: 'API_ERROR',
                errorMessage: $body['message'] ?? 'Failed to query Wompi payout status.',
                rawPayload: $body,
            );
        }

        $wompiStatus = $body['data']['status'] ?? $body['status'] ?? null;
        $status = self::PAYOUT_STATUS_MAP[$wompiStatus] ?? PayoutStatus::Processing;

        $payout->update([
            'status' => $status,
            'provider_response' => $body,
            'processed_at' => $status->isFinal() ? $this->now() : null,
        ]);

        return PayoutResult::success($payout->fresh(), $body);
    }

    /**
     * Base URL for Wompi's Payouts API — a completely separate product
     * from the payment gateway, with its own sandbox/production hosts.
     */
    protected function getPayoutBaseUrl(): string
    {
        $urls = $this->getPayoutConfig('base_url', [
            'sandbox' => 'https://api.sandbox.payouts.wompi.co/v1',
            'production' => 'https://api.payouts.wompi.co/v1',
        ]);

        if (is_string($urls)) {
            return $urls;
        }

        $sandbox = $this->getPayoutConfig('sandbox', true);

        return $sandbox ? $urls['sandbox'] : $urls['production'];
    }

    /**
     * Wompi's Payouts API authenticates via two headers, not a Bearer
     * token: x-api-key and user-principal-id — both separate credentials
     * from the payment gateway's public/private keys.
     */
    protected function payoutHeaders(): array
    {
        return [
            'x-api-key' => $this->getPayoutConfig('api_key'),
            'user-principal-id' => $this->getPayoutConfig('user_principal_id'),
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Generate the integrity signature for Wompi widget.
     */
    protected function generateIntegritySignature(
        string $reference,
        int $amountInCents,
        string $currency,
    ): string {
        $integritySecret = $this->getConfig('integrity_secret');

        $stringToHash = $reference.$amountInCents.$currency.$integritySecret;

        return hash('sha256', $stringToHash);
    }

    /**
     * Find a transaction by its reference.
     */
    protected function findTransactionByReference(string $reference): ?TransactionRecord
    {
        $parsed = $this->parseReference($reference);

        if ($parsed['transaction_id']) {
            return $this->ctx->transactions->find($parsed['transaction_id']);
        }

        return null;
    }
}
