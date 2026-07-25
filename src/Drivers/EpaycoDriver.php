<?php

declare(strict_types=1);

namespace Korbytes\Payments\Drivers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
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
use Korbytes\Payments\Events\PaymentApproved;
use Korbytes\Payments\Events\PaymentCreated;
use Korbytes\Payments\Events\PaymentRejected;
use Korbytes\Payments\Events\WebhookReceived;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Models\PaymentTransaction;
use Korbytes\Payments\Models\Payout;
use Korbytes\Payments\Models\PayoutBeneficiary;
use Korbytes\Payments\Models\Subscription;

/**
 * ePayco Payment Driver Implementation.
 *
 * @see https://docs.epayco.com/
 * @see https://docs.epayco.com/docs/integracion-personalizada
 */
class EpaycoDriver extends AbstractDriver implements PayoutDriverInterface
{
    /**
     * ePayco Payouts (apiflow.epayco.io) status values, per
     * flujo-de-pago-de-proveedores / flujo-de-pago-de-nómina, mapped to our
     * own PayoutStatus.
     */
    protected const array PAYOUT_STATUS_MAP = [
        'SIN_PROCESAR' => PayoutStatus::Pending,
        'PENDIENTE' => PayoutStatus::Processing,
        'ACEPTADO' => PayoutStatus::Completed,
        'RECHAZADO' => PayoutStatus::Rejected,
        'FALLIDO' => PayoutStatus::Failed,
    ];

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
     * ePayco only supports automated reversals for credit card (TC)
     * payments, and the reversal service's endpoint/payload isn't publicly
     * documented (it's gated behind their dashboard-only API portal). PSE
     * and cash payments can never be reversed via API — ePayco requires the
     * merchant to refund those manually.
     *
     * Until the reversal endpoint is confirmed and implemented, ALL ePayco
     * refunds — regardless of payment method — must be handled manually
     * through the ePayco merchant dashboard or their support channel.
     *
     * @see https://docs.epayco.com/docs/api
     */
    public function refund(PaymentTransaction $transaction, ?int $amountInCents = null): RefundResult
    {
        $this->log('info', 'Refund requested but not supported by this driver', [
            'transaction_id' => $transaction->id,
        ]);

        return RefundResult::notSupported(
            $transaction,
            'ePayco refunds are not implemented in this package. Credit card (TC) payments can be reversed '
            .'via ePayco\'s API, but the endpoint is not publicly documented — verify it in your ePayco '
            .'dashboard/support before automating it. PSE and cash payments can never be reversed via API '
            .'and must always be refunded manually through the ePayco dashboard.',
        );
    }

    /**
     * ePayco does have a recurring-billing product (Plan + Customer +
     * Subscription, via the official epayco-php SDK), but this package does
     * NOT implement it: we could not verify — from public documentation,
     * the SDK's source, or a Postman collection someone shared — whether
     * ePayco auto-bills each cycle or requires the merchant to call
     * subscriptions->charge() manually. Shipping either assumption wrong
     * risks silently-uncollected revenue at best and double-charging a
     * customer at worst, so this is deliberately left unimplemented until
     * that's confirmed against a real ePayco sandbox account.
     *
     * @see https://docs.epayco.com/docs/planes
     * @see https://github.com/epayco/epayco-php
     */
    public function createPlan(PlanData $data): PlanResult
    {
        return PlanResult::notSupported(
            'ePayco subscriptions are not implemented in this package — its recurring billing behavior '
            .'(automatic vs. manual per-cycle charging) could not be verified from public documentation. '
            .'Verify it against a real ePayco account before implementing.',
        );
    }

    public function createSubscription(SubscriptionData $data): SubscriptionResult
    {
        return SubscriptionResult::notSupported(
            'ePayco subscriptions are not implemented in this package — see createPlan() for why.',
        );
    }

    public function cancelSubscription(Subscription $subscription): SubscriptionResult
    {
        return SubscriptionResult::notSupported(
            'ePayco subscriptions are not implemented in this package — see createPlan() for why.',
        );
    }

    public function chargeSubscriptionCycle(Subscription $subscription): PaymentResult
    {
        return PaymentResult::failed(
            errorCode: 'SUBSCRIPTIONS_NOT_SUPPORTED',
            errorMessage: 'ePayco subscriptions are not implemented in this package — see createPlan() for why.',
        );
    }

    /**
     * Registers a supplier or payroll beneficiary via ePayco's Payouts
     * product (apiflow.epayco.io), confirmed from official docs
     * (flujo-de-pago-de-proveedores / flujo-de-pago-de-nómina):
     * POST /providers for category=providers, POST /employees for
     * category=payroll.
     *
     * ⚠️ Authentication for this specific API was NOT independently
     * verified — see payoutHeaders() for the assumption being made.
     */
    public function registerBeneficiary(PayoutBeneficiaryData $data): PayoutBeneficiaryResult
    {
        $endpoint = $data->category === 'payroll' ? '/employees' : '/providers';

        try {
            $response = Http::withHeaders($this->payoutHeaders())
                ->timeout(30)
                ->post($this->getPayoutBaseUrl().$endpoint, [
                    'id_epayco' => $this->getPayoutConfig('id_epayco'),
                    'name' => $data->name,
                    'company_name' => $data->name,
                    'document_type' => $data->legalIdType,
                    'document_number' => $data->legalId,
                    'bank' => $data->bankCode,
                    'type_account' => $data->accountType,
                    'account_number' => $data->accountNumber,
                    'email' => $data->email,
                    'phone' => $data->phone,
                ]);
        } catch (\Exception $e) {
            return PayoutBeneficiaryResult::failed('API_ERROR', $e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->failed()) {
            return PayoutBeneficiaryResult::failed(
                errorCode: 'API_ERROR',
                errorMessage: $body['message'] ?? 'Failed to register ePayco payout beneficiary.',
                rawPayload: $body,
            );
        }

        $providerBeneficiaryId = $body['data']['id'] ?? $body['id'] ?? null;

        $beneficiary = PayoutBeneficiary::create([
            'provider' => PaymentProvider::Epayco,
            'provider_beneficiary_id' => $providerBeneficiaryId ? (string) $providerBeneficiaryId : null,
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

        return PayoutBeneficiaryResult::success($beneficiary, $body);
    }

    /**
     * Creates a payout in two steps, per ePayco's confirmed flow:
     * POST /payments/bulk registers the payment (SIN_PROCESAR), then
     * POST /payments/generatePayment triggers the actual dispersal.
     */
    public function createPayout(PayoutData $data): PayoutResult
    {
        $beneficiary = $data->beneficiary;

        if (! $beneficiary->provider_beneficiary_id) {
            return PayoutResult::failed(
                payout: null,
                errorCode: 'MISSING_PROVIDER_BENEFICIARY',
                errorMessage: 'This beneficiary was not registered via the epayco driver (no provider_beneficiary_id) — call registerBeneficiary() with it first.',
            );
        }

        $pocketType = $beneficiary->category === 'payroll' ? 'nomina' : 'proveedor';

        $payout = Payout::create([
            'payout_beneficiary_id' => $beneficiary->id,
            'reference_id' => $data->referenceId,
            'provider' => PaymentProvider::Epayco,
            'amount' => $data->amount,
            'currency' => $data->currency,
            'status' => PayoutStatus::Pending,
            'description' => $data->description,
            'metadata' => $data->metadata,
        ]);

        try {
            $bulkResponse = Http::withHeaders($this->payoutHeaders())
                ->timeout(30)
                ->post($this->getPayoutBaseUrl().'/payments/bulk', [[
                    'id_epayco' => $this->getPayoutConfig('id_epayco'),
                    'recipient_id' => $beneficiary->provider_beneficiary_id,
                    'pocket_type' => $pocketType,
                    'total' => $data->amount / 100,
                    'reference' => $data->referenceId,
                    'description' => $data->description,
                ]]);
        } catch (\Exception $e) {
            $payout->update(['status' => PayoutStatus::Failed]);

            return PayoutResult::failed($payout->fresh(), 'API_ERROR', $e->getMessage());
        }

        $bulkBody = $bulkResponse->json() ?? [];

        if ($bulkResponse->failed()) {
            $payout->update(['status' => PayoutStatus::Failed, 'provider_response' => $bulkBody]);

            return PayoutResult::failed(
                payout: $payout->fresh(),
                errorCode: 'API_ERROR',
                errorMessage: $bulkBody['message'] ?? 'Failed to create ePayco payment.',
                rawPayload: $bulkBody,
            );
        }

        $paymentId = $bulkBody['data'][0]['id_payment'] ?? $bulkBody['id_payment'] ?? null;

        if (! $paymentId) {
            $payout->update(['status' => PayoutStatus::Failed, 'provider_response' => $bulkBody]);

            return PayoutResult::failed($payout->fresh(), 'API_ERROR', 'ePayco did not return a payment id from payments/bulk.', $bulkBody);
        }

        try {
            $dispersalResponse = Http::withHeaders($this->payoutHeaders())
                ->timeout(30)
                ->post($this->getPayoutBaseUrl().'/payments/generatePayment', [
                    'id_epayco' => $this->getPayoutConfig('id_epayco'),
                    'target' => $pocketType,
                    'id_payment' => [$paymentId],
                ]);
        } catch (\Exception $e) {
            $payout->update(['status' => PayoutStatus::Failed, 'provider_payout_id' => (string) $paymentId]);

            return PayoutResult::failed($payout->fresh(), 'API_ERROR', $e->getMessage());
        }

        $dispersalBody = $dispersalResponse->json() ?? [];

        $payout->update([
            'provider_payout_id' => (string) $paymentId,
            'status' => $dispersalResponse->failed() ? PayoutStatus::Failed : PayoutStatus::Processing,
            'provider_response' => ['bulk' => $bulkBody, 'dispersal' => $dispersalBody],
        ]);

        if ($dispersalResponse->failed()) {
            return PayoutResult::failed(
                payout: $payout->fresh(),
                errorCode: 'API_ERROR',
                errorMessage: $dispersalBody['message'] ?? 'Failed to generate ePayco payment dispersal.',
                rawPayload: $dispersalBody,
            );
        }

        return PayoutResult::success($payout->fresh(), $dispersalBody);
    }

    public function queryPayoutStatus(Payout $payout): PayoutResult
    {
        if (! $payout->provider_payout_id) {
            return PayoutResult::failed(
                payout: $payout,
                errorCode: 'NO_PROVIDER_ID',
                errorMessage: 'No ePayco payment id available for query.',
            );
        }

        try {
            $response = Http::withHeaders($this->payoutHeaders())
                ->timeout(30)
                ->post($this->getPayoutBaseUrl().'/payments/findone', [
                    'id_epayco' => $this->getPayoutConfig('id_epayco'),
                    'id_payment' => $payout->provider_payout_id,
                ]);
        } catch (\Exception $e) {
            return PayoutResult::failed($payout, 'API_ERROR', $e->getMessage());
        }

        $body = $response->json() ?? [];

        if ($response->failed()) {
            return PayoutResult::failed(
                payout: $payout,
                errorCode: 'API_ERROR',
                errorMessage: $body['message'] ?? 'Failed to query ePayco payment status.',
                rawPayload: $body,
            );
        }

        $epaycoStatus = $body['data']['status'] ?? $body['status'] ?? null;
        $status = self::PAYOUT_STATUS_MAP[$epaycoStatus] ?? PayoutStatus::Processing;

        $payout->update([
            'status' => $status,
            'provider_response' => $body,
            'processed_at' => $status->isFinal() ? now() : null,
        ]);

        return PayoutResult::success($payout->fresh(), $body);
    }

    protected function getPayoutBaseUrl(): string
    {
        return $this->getPayoutConfig('base_url', 'https://apiflow.epayco.io/payouts/api/v2');
    }

    /**
     * ⚠️ ASSUMPTION, not independently verified: this reuses the same
     * public/private-key → bearer-token login pattern confirmed for
     * ePayco's other APIs (see the official epayco-php SDK's
     * Client::authentication(), which POSTs public_key/private_key to
     * /v1/auth/login and reads back a bearer_token). Whether
     * apiflow.epayco.io's Payouts product uses this exact same login
     * endpoint/shape was not confirmed — verify against a real sandbox
     * account before relying on this in production. See USAGE.md.
     */
    protected function payoutHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.$this->payoutBearerToken(),
            'Content-Type' => 'application/json',
        ];
    }

    protected function payoutBearerToken(): string
    {
        $response = Http::withHeaders(['Content-Type' => 'application/json'])
            ->timeout(30)
            ->post($this->getPayoutBaseUrl().'/login', [
                'public_key' => $this->getPayoutConfig('public_key'),
                'private_key' => $this->getPayoutConfig('private_key'),
            ]);

        $body = $response->json() ?? [];
        $token = $body['token'] ?? $body['bearer_token'] ?? null;

        if (! $token) {
            throw new \RuntimeException('Failed to authenticate with the ePayco Payouts API — see USAGE.md, this auth flow is unverified.');
        }

        return $token;
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
