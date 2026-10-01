<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Enums\PaymentStatus;
use Korbytes\Payments\Contracts\Records\TransactionRecord;

/**
 * Data transfer object for webhook processing results.
 */
final readonly class WebhookResult
{
    public function __construct(
        public bool $success,
        public ?TransactionRecord $transaction,
        public ?PaymentStatus $status,
        public ?string $providerTransactionId = null,
        public ?string $providerReference = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawPayload = [],
    ) {}

    public static function success(
        TransactionRecord $transaction,
        PaymentStatus $status,
        ?string $providerTransactionId = null,
        ?string $providerReference = null,
        array $rawPayload = [],
    ): self {
        return new self(
            success: true,
            transaction: $transaction,
            status: $status,
            providerTransactionId: $providerTransactionId,
            providerReference: $providerReference,
            rawPayload: $rawPayload,
        );
    }

    public static function failed(
        ?TransactionRecord $transaction,
        string $errorCode,
        string $errorMessage,
        array $rawPayload = [],
    ): self {
        return new self(
            success: false,
            transaction: $transaction,
            status: null,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawPayload: $rawPayload,
        );
    }

    public static function notFound(string $reference, array $rawPayload = []): self
    {
        return new self(
            success: false,
            transaction: null,
            status: null,
            errorCode: 'TRANSACTION_NOT_FOUND',
            errorMessage: "Transaction not found for reference: {$reference}",
            rawPayload: $rawPayload,
        );
    }

    public static function duplicate(TransactionRecord $transaction, array $rawPayload = []): self
    {
        return new self(
            success: true,
            transaction: $transaction,
            status: $transaction->getAttribute('status'),
            errorCode: 'DUPLICATE_WEBHOOK',
            errorMessage: 'Webhook already processed (idempotency)',
            rawPayload: $rawPayload,
        );
    }

    /**
     * Check if the payment was approved.
     */
    public function isApproved(): bool
    {
        return $this->status === PaymentStatus::Approved;
    }

    /**
     * Check if the payment is in a final state.
     */
    public function isFinal(): bool
    {
        return $this->status?->isFinal() ?? false;
    }
}
