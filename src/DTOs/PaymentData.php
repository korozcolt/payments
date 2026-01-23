<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

/**
 * Data transfer object for payment input data.
 *
 * This DTO decouples the payment package from any domain models.
 * All payment data should be passed through this DTO.
 */
final readonly class PaymentData
{
    /**
     * @param  string  $referenceId  Unique identifier for the order/purchase
     * @param  int  $amount  Amount in cents (e.g., 50000 = $500.00)
     * @param  string  $currency  ISO 4217 currency code (e.g., COP, USD)
     * @param  array{name: string, email: string, phone?: string}  $customer  Customer information
     * @param  string|null  $returnUrl  URL to redirect after payment completion
     * @param  string|null  $webhookUrl  URL for payment provider webhooks
     * @param  string|null  $description  Payment description
     * @param  array<string, mixed>  $metadata  Additional metadata to store
     * @param  array<array{id: string, title: string, description?: string, quantity: int, unit_price: int}>  $items  Line items for detailed invoicing
     */
    public function __construct(
        public string $referenceId,
        public int $amount,
        public string $currency = 'COP',
        public array $customer = [],
        public ?string $returnUrl = null,
        public ?string $webhookUrl = null,
        public ?string $description = null,
        public array $metadata = [],
        public array $items = [],
    ) {}

    /**
     * Create from an array.
     *
     * @param array{
     *     reference_id: string,
     *     amount: int,
     *     currency?: string,
     *     customer?: array{name: string, email: string, phone?: string},
     *     return_url?: string,
     *     webhook_url?: string,
     *     description?: string,
     *     metadata?: array<string, mixed>,
     *     items?: array<array{id: string, title: string, description?: string, quantity: int, unit_price: int}>,
     * } $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            referenceId: $data['reference_id'],
            amount: $data['amount'],
            currency: $data['currency'] ?? 'COP',
            customer: $data['customer'] ?? [],
            returnUrl: $data['return_url'] ?? null,
            webhookUrl: $data['webhook_url'] ?? null,
            description: $data['description'] ?? null,
            metadata: $data['metadata'] ?? [],
            items: $data['items'] ?? [],
        );
    }

    /**
     * Convert to array.
     */
    public function toArray(): array
    {
        return [
            'reference_id' => $this->referenceId,
            'amount' => $this->amount,
            'currency' => $this->currency,
            'customer' => $this->customer,
            'return_url' => $this->returnUrl,
            'webhook_url' => $this->webhookUrl,
            'description' => $this->description,
            'metadata' => $this->metadata,
            'items' => $this->items,
        ];
    }

    /**
     * Get customer name.
     */
    public function getCustomerName(): ?string
    {
        return $this->customer['name'] ?? null;
    }

    /**
     * Get customer email.
     */
    public function getCustomerEmail(): ?string
    {
        return $this->customer['email'] ?? null;
    }

    /**
     * Get customer phone.
     */
    public function getCustomerPhone(): ?string
    {
        return $this->customer['phone'] ?? null;
    }

    /**
     * Get amount formatted for display.
     */
    public function getFormattedAmount(): string
    {
        return '$'.number_format($this->amount / 100, 0, ',', '.');
    }
}
