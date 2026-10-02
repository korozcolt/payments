<?php

declare(strict_types=1);

namespace Korbytes\Payments\Enums;

/**
 * Payout (money sent to a third party) lifecycle status.
 */
enum PayoutStatus: string
{
    case Pending = 'pending';
    case Processing = 'processing';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Failed = 'failed';

    public function getLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Processing => 'Processing',
            self::Completed => 'Completed',
            self::Rejected => 'Rejected',
            self::Failed => 'Failed',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Rejected, self::Failed], true);
    }
}
