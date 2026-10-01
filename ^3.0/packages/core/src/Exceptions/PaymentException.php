<?php

declare(strict_types=1);

namespace Korbytes\Payments\Exceptions;

use Exception;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * Base exception for payment operations.
 */
class PaymentException extends Exception
{
    public function __construct(
        string $message,
        public readonly ?PaymentProvider $provider = null,
        public readonly ?string $errorCode = null,
        public readonly array $context = [],
        ?Exception $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function notConfigured(PaymentProvider $provider): self
    {
        return new self(
            message: "Payment provider {$provider->value} is not configured",
            provider: $provider,
            errorCode: 'PROVIDER_NOT_CONFIGURED',
        );
    }

    public static function notImplemented(PaymentProvider $provider): self
    {
        return new self(
            message: "Payment provider {$provider->value} is not implemented yet",
            provider: $provider,
            errorCode: 'PROVIDER_NOT_IMPLEMENTED',
        );
    }

    public static function apiError(PaymentProvider $provider, string $message, array $context = []): self
    {
        return new self(
            message: $message,
            provider: $provider,
            errorCode: 'API_ERROR',
            context: $context,
        );
    }

    /**
     * The provider doesn't expose a (usable) refund API for this
     * transaction/payment method — it must be handled manually.
     */
    public static function refundNotSupported(PaymentProvider $provider, string $reason): self
    {
        return new self(
            message: "Refund not supported by {$provider->value}: {$reason}",
            provider: $provider,
            errorCode: 'REFUND_NOT_SUPPORTED',
        );
    }

    /**
     * The provider has no payouts (third-party disbursement) API at all —
     * its driver doesn't implement PayoutDriverInterface.
     */
    public static function payoutsNotSupported(PaymentProvider $provider): self
    {
        return new self(
            message: "{$provider->value} has no payouts API — its driver does not implement PayoutDriverInterface.",
            provider: $provider,
            errorCode: 'PAYOUTS_NOT_SUPPORTED',
        );
    }
}
