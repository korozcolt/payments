<?php

declare(strict_types=1);

namespace Korbytes\Payments\Enums;

/**
 * Subscription lifecycle status.
 */
enum SubscriptionStatus: string
{
    case Trialing = 'trialing';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    public function getLabel(): string
    {
        return match ($this) {
            self::Trialing => 'Trialing',
            self::Active => 'Active',
            self::PastDue => 'Past Due',
            self::Cancelled => 'Cancelled',
            self::Expired => 'Expired',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Trialing, self::Active], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Cancelled, self::Expired], true);
    }
}
