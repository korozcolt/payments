<?php

declare(strict_types=1);

namespace Korbytes\Payments\DTOs;

use Korbytes\Payments\Models\Subscription;

/**
 * Result of creating, cancelling, or updating a subscription.
 */
final readonly class SubscriptionResult
{
    public function __construct(
        public bool $success,
        public ?Subscription $subscription,
        public ?string $errorCode = null,
        public ?string $errorMessage = null,
        public array $rawPayload = [],
    ) {}

    public static function success(Subscription $subscription, array $rawPayload = []): self
    {
        return new self(success: true, subscription: $subscription, rawPayload: $rawPayload);
    }

    public static function failed(
        ?Subscription $subscription,
        string $errorCode,
        string $errorMessage,
        array $rawPayload = [],
    ): self {
        return new self(
            success: false,
            subscription: $subscription,
            errorCode: $errorCode,
            errorMessage: $errorMessage,
            rawPayload: $rawPayload,
        );
    }

    /**
     * The provider doesn't have real, verified subscription support in this
     * package (currently: ePayco — see USAGE.md).
     */
    public static function notSupported(string $reason): self
    {
        return new self(success: false, subscription: null, errorCode: 'SUBSCRIPTIONS_NOT_SUPPORTED', errorMessage: $reason);
    }
}
