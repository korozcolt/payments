<?php

declare(strict_types=1);

namespace Korbytes\Payments\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentCreated;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Events\WebhookReceived;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Models\PaymentTransaction;

/**
 * Wompi Payment Driver Implementation.
 *
 * @see https://docs.wompi.co/docs/colombia/widget-checkout-web/
 * @see https://docs.wompi.co/docs/colombia/eventos/
 */
class WompiDriver extends AbstractDriver
{
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
        $transaction = DB::transaction(function () use ($paymentData) {
            return PaymentTransaction::create([
                'reference_id' => $paymentData->referenceId,
                'provider' => PaymentProvider::Wompi,
                'amount' => $paymentData->amount,
                'currency' => $paymentData->currency,
                'status' => PaymentStatus::Pending,
                'idempotency_key' => (string) Str::uuid(),
                'metadata' => $paymentData->metadata,
                'initiated_at' => now(),
            ]);
        });

        $reference = $this->generateReference($paymentData->referenceId, $transaction->id);
        $signature = $this->generateIntegritySignature(
            reference: $reference,
            amountInCents: $paymentData->amount,
            currency: $paymentData->currency,
        );

        $redirectUrl = $paymentData->returnUrl ?? config('payments.urls.return');

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

        PaymentCreated::dispatch($transaction, $result);

        return $result;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
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
            $value = data_get($data, $property, '');
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

    public function processWebhook(Request $request): WebhookResult
    {
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

            WebhookReceived::dispatch(PaymentProvider::Wompi, $result, $payload);

            return $result;
        }

        $transaction = $this->findTransactionByReference($reference);

        if (! $transaction) {
            $this->log('warning', 'Transaction not found for reference', [
                'reference' => $reference,
            ]);

            $result = WebhookResult::notFound($reference, $payload);

            WebhookReceived::dispatch(PaymentProvider::Wompi, $result, $payload);

            return $result;
        }

        // Check for idempotency
        if ($transaction->webhook_received_at && $transaction->isFinal()) {
            $this->log('info', 'Webhook already processed (idempotency)', [
                'transaction_id' => $transaction->id,
            ]);

            $result = WebhookResult::duplicate($transaction, $payload);

            WebhookReceived::dispatch(PaymentProvider::Wompi, $result, $payload);

            return $result;
        }

        $status = self::STATUS_MAP[$wompiStatus] ?? PaymentStatus::Pending;

        // Update transaction
        DB::transaction(function () use ($transaction, $status, $providerTransactionId, $payload) {
            $transaction->update([
                'status' => $status,
                'provider_transaction_id' => $providerTransactionId,
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
            providerTransactionId: $providerTransactionId,
            rawPayload: $payload,
        );

        WebhookReceived::dispatch(PaymentProvider::Wompi, $result, $payload);

        // Dispatch status-specific events
        if ($status === PaymentStatus::Approved) {
            PaymentApproved::dispatch($transaction->fresh(), $result);
        } elseif (in_array($status, [PaymentStatus::Rejected, PaymentStatus::Voided])) {
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
                    'completed_at' => $status->isFinal() ? now() : null,
                ]);

                if ($status === PaymentStatus::Approved) {
                    PaymentApproved::dispatch($transaction->fresh(), WebhookResult::success(
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
    protected function findTransactionByReference(string $reference): ?PaymentTransaction
    {
        $parsed = $this->parseReference($reference);

        if ($parsed['transaction_id']) {
            return PaymentTransaction::find($parsed['transaction_id']);
        }

        return null;
    }
}
