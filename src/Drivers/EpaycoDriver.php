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
 * ePayco Payment Driver Implementation.
 *
 * @see https://docs.epayco.com/
 * @see https://docs.epayco.com/docs/integracion-personalizada
 */
class EpaycoDriver extends AbstractDriver
{
    /**
     * Map ePayco response codes to internal statuses.
     *
     * x_cod_response values:
     * 1 = Approved
     * 2 = Rejected
     * 3 = Pending
     * 4 = Failed
     * 6 = Reversed
     * 7 = Held (retained)
     * 9 = Expired
     * 10 = Abandoned
     * 11 = Cancelled
     * 12 = Antifraud
     */
    protected const array STATUS_MAP = [
        1 => PaymentStatus::Approved,
        2 => PaymentStatus::Rejected,
        3 => PaymentStatus::Pending,
        4 => PaymentStatus::Rejected,
        6 => PaymentStatus::Refunded,
        7 => PaymentStatus::Pending,
        9 => PaymentStatus::Expired,
        10 => PaymentStatus::Voided,
        11 => PaymentStatus::Voided,
        12 => PaymentStatus::Rejected,
    ];

    /**
     * Map ePayco text statuses to codes.
     */
    protected const array TEXT_STATUS_MAP = [
        'Aceptada' => 1,
        'Rechazada' => 2,
        'Pendiente' => 3,
        'Fallida' => 4,
        'Reversada' => 6,
        'Retenida' => 7,
        'Expirada' => 9,
        'Abandonada' => 10,
        'Cancelada' => 11,
        'Fraude' => 12,
    ];

    public function getName(): string
    {
        return PaymentProvider::Epayco->value;
    }

    public function getWidgetUrl(): string
    {
        return $this->getConfig('widget_url', 'https://checkout.epayco.co/checkout.js');
    }

    public function getPublicKey(): ?string
    {
        return $this->getConfig('public_key');
    }

    public function getBaseUrl(): string
    {
        return $this->getConfig('base_url', 'https://secure.epayco.co');
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
                'provider' => PaymentProvider::Epayco,
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

        $this->log('info', 'Payment intent created', [
            'transaction_id' => $transaction->id,
            'reference' => $reference,
        ]);

        $result = PaymentResult::success(
            transaction: $transaction,
            provider: PaymentProvider::Epayco,
            widgetUrl: $this->getWidgetUrl(),
            publicKey: $this->getPublicKey(),
            amountInCents: $paymentData->amount,
            currency: $paymentData->currency,
            reference: $reference,
            signature: '', // ePayco doesn't require pre-generated signature
            redirectUrl: $redirectUrl,
            extra: [
                'name' => $paymentData->description ?? 'Payment',
                'description' => $paymentData->description ?? 'Payment for '.$paymentData->referenceId,
                'invoice' => $reference,
                'tax_base' => '0',
                'tax' => '0',
                'tax_ico' => '0',
                'country' => 'co',
                'lang' => 'es',
                'test' => $this->isSandbox(),
                'external' => 'false',
                'response' => $redirectUrl,
                'confirmation' => $webhookUrl,
                'customer_email' => $paymentData->getCustomerEmail(),
                'customer_name' => $paymentData->getCustomerName(),
                'customer_phone' => $paymentData->getCustomerPhone(),
                'extra1' => $paymentData->referenceId,
                'extra2' => (string) $transaction->id,
                'extra3' => $paymentData->metadata['order_ulid'] ?? '',
            ],
        );

        PaymentCreated::dispatch($transaction, $result);

        return $result;
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $this->log('debug', 'Verifying webhook signature');

        $receivedSignature = $request->input('x_signature');

        if (! $receivedSignature) {
            throw new InvalidWebhookSignatureException('Missing x_signature in webhook payload');
        }

        $clientId = $this->getConfig('p_cust_id_cliente');
        $secretKey = $this->getConfig('p_key');
        $refPayco = $request->input('x_ref_payco');
        $transactionId = $request->input('x_transaction_id');
        $amount = $request->input('x_amount');
        $currencyCode = $request->input('x_currency_code');

        if (! $clientId || ! $secretKey) {
            throw new InvalidWebhookSignatureException('ePayco credentials not configured');
        }

        if (! $refPayco || ! $transactionId || ! $amount || ! $currencyCode) {
            throw new InvalidWebhookSignatureException('Missing required fields for signature verification');
        }

        $stringToHash = implode('^', [
            $clientId,
            $secretKey,
            $refPayco,
            $transactionId,
            $amount,
            $currencyCode,
        ]);

        $expectedSignature = hash('sha256', $stringToHash);

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
            'x_ref_payco' => $payload['x_ref_payco'] ?? 'unknown',
            'x_id_invoice' => $payload['x_id_invoice'] ?? 'unknown',
        ]);

        $reference = $payload['x_id_invoice'] ?? $payload['x_extra1'] ?? null;
        $providerTransactionId = $payload['x_ref_payco'] ?? null;
        $codResponse = (int) ($payload['x_cod_response'] ?? 3);
        $responseText = $payload['x_response'] ?? null;

        if ($codResponse === 0 && $responseText) {
            $codResponse = self::TEXT_STATUS_MAP[$responseText] ?? 3;
        }

        if (! $reference) {
            $transactionId = $payload['x_extra2'] ?? null;
            if ($transactionId) {
                $transaction = PaymentTransaction::find($transactionId);
                if ($transaction) {
                    $reference = $this->generateReference($transaction->reference_id, $transaction->id);
                }
            }
        }

        if (! $reference) {
            $this->log('error', 'Missing reference in webhook payload');

            $result = WebhookResult::failed(
                transaction: null,
                errorCode: 'MISSING_REFERENCE',
                errorMessage: 'Missing reference in webhook payload',
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::Epayco, $result, $payload);

            return $result;
        }

        $transaction = $this->findTransactionByReference($reference);

        if (! $transaction) {
            $this->log('warning', 'Transaction not found for reference', [
                'reference' => $reference,
            ]);

            $result = WebhookResult::notFound($reference, $payload);

            WebhookReceived::dispatch(PaymentProvider::Epayco, $result, $payload);

            return $result;
        }

        // Verify amount matches
        $webhookAmount = (int) (floatval($payload['x_amount'] ?? 0) * 100);
        if ($webhookAmount > 0 && $webhookAmount !== $transaction->amount) {
            $this->log('warning', 'Amount mismatch in webhook', [
                'expected' => $transaction->amount,
                'received' => $webhookAmount,
            ]);

            $result = WebhookResult::failed(
                transaction: $transaction,
                errorCode: 'AMOUNT_MISMATCH',
                errorMessage: 'Webhook amount does not match transaction amount',
                rawPayload: $payload,
            );

            WebhookReceived::dispatch(PaymentProvider::Epayco, $result, $payload);

            return $result;
        }

        // Check for idempotency
        if ($transaction->webhook_received_at && $transaction->isFinal()) {
            $this->log('info', 'Webhook already processed (idempotency)', [
                'transaction_id' => $transaction->id,
            ]);

            $result = WebhookResult::duplicate($transaction, $payload);

            WebhookReceived::dispatch(PaymentProvider::Epayco, $result, $payload);

            return $result;
        }

        $status = self::STATUS_MAP[$codResponse] ?? PaymentStatus::Pending;

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

        WebhookReceived::dispatch(PaymentProvider::Epayco, $result, $payload);

        if ($status === PaymentStatus::Approved) {
            PaymentApproved::dispatch($transaction->fresh(), $result);
        } elseif (in_array($status, [PaymentStatus::Rejected, PaymentStatus::Voided, PaymentStatus::Expired])) {
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
                endpoint: '/restpagos/transaction/response.json',
                query: [
                    'ref_payco' => $transaction->provider_transaction_id,
                    'public_key' => $this->getPublicKey(),
                ],
            );

            $data = $response['data'] ?? $response;
            $codResponse = (int) ($data['x_cod_response'] ?? $data['x_cod_transaction_state'] ?? 3);
            $status = self::STATUS_MAP[$codResponse] ?? PaymentStatus::Pending;

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
                        providerTransactionId: $data['x_ref_payco'] ?? $transaction->provider_transaction_id,
                        rawPayload: $response,
                    ));
                }
            }

            return WebhookResult::success(
                transaction: $transaction->fresh(),
                status: $status,
                providerTransactionId: $data['x_ref_payco'] ?? $transaction->provider_transaction_id,
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
