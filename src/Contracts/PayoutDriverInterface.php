<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts;

use Korbytes\Payments\DTOs\PayoutBeneficiaryData;
use Korbytes\Payments\DTOs\PayoutBeneficiaryResult;
use Korbytes\Payments\DTOs\PayoutData;
use Korbytes\Payments\DTOs\PayoutResult;
use Korbytes\Payments\Models\Payout;

/**
 * Contract for drivers that support sending money to third parties
 * (payouts/disbursements), as opposed to PaymentDriverInterface which
 * charges customers.
 *
 * This is a SEPARATE interface, not part of PaymentDriverInterface,
 * because payout support is genuinely provider-specific: MercadoPago has
 * no payouts API at all, so its driver does not implement this interface.
 * Use PaymentManager::payoutDriver() to resolve a driver that does.
 */
interface PayoutDriverInterface
{
    /**
     * Configure the driver with payout-specific credentials.
     *
     * These are DIFFERENT credentials from the payment gateway config
     * (configure()) — payouts require their own API keys and, for Wompi,
     * a separate merchant module activation. See USAGE.md.
     */
    public function configurePayouts(array $config): static;

    /**
     * Register a beneficiary (payee) for future payouts.
     *
     * Not every provider has a server-side beneficiary object — see each
     * driver's implementation. Bank details are validated by the
     * provider's own API.
     */
    public function registerBeneficiary(PayoutBeneficiaryData $data): PayoutBeneficiaryResult;

    /**
     * Send a payout to a registered beneficiary.
     */
    public function createPayout(PayoutData $data): PayoutResult;

    /**
     * Query a payout's current status directly from the provider's API.
     */
    public function queryPayoutStatus(Payout $payout): PayoutResult;
}
