<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts;

use Illuminate\Http\Request;
use Korbytes\Payments\DTOs\PaymentData;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\DTOs\PlanData;
use Korbytes\Payments\DTOs\PlanResult;
use Korbytes\Payments\DTOs\RefundResult;
use Korbytes\Payments\DTOs\SubscriptionData;
use Korbytes\Payments\DTOs\SubscriptionResult;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Models\PaymentTransaction;
use Korbytes\Payments\Models\Subscription;

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
     * Refund an approved payment, fully or partially.
     *
     * Automated refund support varies by provider and payment method — see
     * each driver's implementation and USAGE.md. When the provider doesn't
     * expose a usable refund API for this transaction, this returns
     * RefundResult::notSupported() rather than throwing, so callers can
     * handle "must be refunded manually" as a normal outcome.
     *
     * @param  int|null  $amountInCents  Partial refund amount; null refunds the full transaction amount.
     */
    public function refund(PaymentTransaction $transaction, ?int $amountInCents = null): RefundResult;

    /**
     * Create a recurring billing plan.
     *
     * Real subscription support varies by provider — see USAGE.md. When a
     * provider has no verified subscription support in this package, this
     * returns PlanResult::notSupported() rather than throwing.
     */
    public function createPlan(PlanData $data): PlanResult;

    /**
     * Subscribe a customer to a plan using an already-tokenized payment
     * method (this package never handles raw card data).
     */
    public function createSubscription(SubscriptionData $data): SubscriptionResult;

    /**
     * Cancel an active subscription.
     */
    public function cancelSubscription(Subscription $subscription): SubscriptionResult;

    /**
     * Charge one billing cycle for a subscription.
     *
     * For providers with their own recurring-billing engine (MercadoPago),
     * this is not invoked by this package's own scheduler — cycles are
     * billed by the provider and reflected via processWebhook(). It exists
     * here for interface parity and manual/on-demand use, and returns a
     * failed PaymentResult explaining that for such providers.
     *
     * For providers with no such engine (Wompi), this is the method the
     * `payments:process-subscriptions` command calls to actually bill each
     * due cycle.
     */
    public function chargeSubscriptionCycle(Subscription $subscription): PaymentResult;

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
