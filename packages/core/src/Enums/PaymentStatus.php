<?php

declare(strict_types=1);

namespace Korbytes\Payments\Enums;

/**
 * Payment transaction status enum.
 */
enum PaymentStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Expired = 'expired';
    case Refunded = 'refunded';
    case Voided = 'voided';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::Expired => 'Expired',
            self::Refunded => 'Refunded',
            self::Voided => 'Voided',
        };
    }

    public function isSuccessful(): bool
    {
        return $this === self::Approved;
    }

    public function isFinal(): bool
    {
        return in_array($this, [
            self::Approved,
            self::Rejected,
            self::Expired,
            self::Refunded,
            self::Voided,
        ]);
    }
}
