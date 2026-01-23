<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts;

use Illuminate\Http\Request;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\DTOs\WebhookResult;

/**
 * Contract for payment drivers.
 *
 * All payment drivers (Wompi, MercadoPago, ePayco) must implement this interface
 * to ensure consistent behavior across the payment system.
 */
interface PaymentDriverInterface
{
    /**
     * Configure the driver with credentials and settings.
     *
     * @param array{
     *     sandbox?: bool,
     *     public_key?: string,
     *     private_key?: string,
     *     access_token?: string,
     *     integrity_secret?: string,
     *     events_secret?: string,
     *     webhook_secret?: string,
     *     p_cust_id_cliente?: string,
     *     p_key?: string,
     * } $config
     */
    public function configure(array $config): static;

    /**
     * Create a payment intent/transaction for the given payment data.
     *
     * This prepares the payment but does not process it yet.
     * Returns data needed by the frontend to complete the payment.
     */
    public function charge(PaymentData $paymentData): PaymentResult;

    /**
     * Verify the webhook signature.
     *
     * @throws \Korbytes\Payments\Exceptions\InvalidWebhookSignatureException
     */
    public function verifyWebhookSignature(Request $request): bool;

    /**
     * Process the webhook payload.
     *
     * This method should:
     * 1. Parse the webhook payload
     * 2. Find the corresponding transaction
     * 3. Update the transaction status
     * 4. Return the result
     */
    public function processWebhook(Request $request): WebhookResult;

    /**
     * Query the payment status directly from the provider's API.
     *
     * Useful for reconciliation or when webhooks fail.
     */
    public function queryStatus(string $transactionId): WebhookResult;

    /**
     * Check if this driver is properly configured and ready to process payments.
     */
    public function isConfigured(): bool;

    /**
     * Get the driver/provider name.
     */
    public function getName(): string;

    /**
     * Get the widget URL for frontend integration.
     */
    public function getWidgetUrl(): string;

    /**
     * Get the public key for frontend integration.
     */
    public function getPublicKey(): ?string;

    /**
     * Get the base API URL.
     */
    public function getBaseUrl(): string;

    /**
     * Check if the driver is in sandbox mode.
     */
    public function isSandbox(): bool;
}
