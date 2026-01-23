<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Models\PaymentTransaction;

/**
 * Data transfer object for payment creation result.
 *
 * Contains all the information needed by the frontend to render
 * the payment widget and complete the transaction.
 */
final readonly class PaymentResult
{
    public function __construct(
        public bool $success,
        public ?PaymentTransaction $transaction,
        public ?PaymentProvider $provider,
        public ?string $widgetUrl = null,
        public ?string $publicKey = null,
        public ?int $amountInCents = null,
        public ?string $currency = null,
        public ?string $reference = null,
        public ?string $signature = null,
        public ?string $redirectUrl = null,
        public array $extra = [],
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
    ) {}

    /**
     * Create a successful payment result.
     */
    public static function success(
        PaymentTransaction $transaction,
        PaymentProvider $provider,
        string $widgetUrl,
        string $publicKey,
        int $amountInCents,
        string $currency,
        string $reference,
        string $signature,
        ?string $redirectUrl = null,
        array $extra = [],
    ): self {
        return new self(
            success: true,
            transaction: $transaction,
            provider: $provider,
            widgetUrl: $widgetUrl,
            publicKey: $publicKey,
            amountInCents: $amountInCents,
            currency: $currency,
            reference: $reference,
            signature: $signature,
            redirectUrl: $redirectUrl,
            extra: $extra,
        );
    }

    /**
     * Create a failed payment result.
     */
    public static function failed(
        string $errorCode,
        string $errorMessage,
        ?PaymentTransaction $transaction = null,
    ): self {
        return new self(
            success: false,
            transaction: $transaction,
            provider: null,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
        );
    }

    /**
     * Convert to array for frontend consumption.
     */
    public function toArray(): array
    {
        return [
            'success' => $this->success,
            'transaction_id' => $this->transaction?->id,
            'transaction_ulid' => $this->transaction?->ulid,
            'provider' => $this->provider?->value,
            'widget_url' => $this->widgetUrl,
            'public_key' => $this->publicKey,
            'amount_in_cents' => $this->amountInCents,
            'currency' => $this->currency,
            'reference' => $this->reference,
            'signature' => $this->signature,
            'redirect_url' => $this->redirectUrl,
            'extra' => $this->extra,
            'error_code' => $this->errorCode,
            'error_message' => $this->errorMessage,
        ];
    }
}
