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
use MercadoPago\Client\Payment\PaymentClient;
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
