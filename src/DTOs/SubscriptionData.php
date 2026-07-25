<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Models\SubscriptionPlan;

/**
 * Input data for subscribing a customer to a plan.
 *
 * Card data is never handled directly by this package — $paymentToken must
 * already be a tokenized card/payment method obtained client-side (Wompi
 * widget, MercadoPago card form, etc.) for PCI compliance reasons.
 */
final readonly class SubscriptionData
{
    /**
     * @param  array{name: string, email: string, phone?: string}  $customer
     * @param  array<string, mixed>  $providerOptions  Driver-specific extra fields
     *                                                 (e.g. Wompi's acceptance_token/accept_personal_auth).
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public SubscriptionPlan $plan,
        public string $referenceId,
        public string $paymentToken,
        public array $customer = [],
        public array $providerOptions = [],
        public array $metadata = [],
    ) {}

    public function getCustomerName(): ?string
    {
        return $this->customer['name'] ?? null;
    }

    public function getCustomerEmail(): ?string
    {
        return $this->customer['email'] ?? null;
    }

    public function getCustomerPhone(): ?string
    {
        return $this->customer['phone'] ?? null;
    }
}
