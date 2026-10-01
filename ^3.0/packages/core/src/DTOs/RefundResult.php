<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Contracts\Records\TransactionRecord;

/**
 * Data transfer object for refund operation results.
 *
 * Not every provider can refund automatically — see each driver's refund()
 * implementation and USAGE.md for what's actually supported vs manual.
 */
final readonly class RefundResult
{
    public function __construct(
        public bool $success,
        public ?TransactionRecord $transaction,
        public ?int $refundedAmountInCents = null,
        public ?string $providerRefundId = null,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawPayload = [],
    ) {}

    public static function success(
        TransactionRecord $transaction,
        int $refundedAmountInCents,
        ?string $providerRefundId = null,
        array $rawPayload = [],
    ): self {
        return new self(
            success: true,
            transaction: $transaction,
            refundedAmountInCents: $refundedAmountInCents,
            providerRefundId: $providerRefundId,
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
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawPayload: $rawPayload,
        );
    }

    /**
     * The provider doesn't expose a (usable) refund API for this
     * transaction/payment method — it must be handled manually.
     */
    public static function notSupported(?TransactionRecord $transaction, string $reason): self
    {
        return new self(
            success: false,
            transaction: $transaction,
            errorCode: 'REFUND_NOT_SUPPORTED',
            errorMessage: $reason,
        );
    }
}
