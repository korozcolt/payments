<?php

declare(strict_types=1);

namespace Korbytes\Payments\Enums;

use Carbon\CarbonInterface;

/**
 * Recurring billing interval for a subscription plan.
 */
enum BillingInterval: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';
    case Year = 'year';

    /**
     * Advance a date by this interval, repeated $count times.
     */
    public function addTo(CarbonInterface $date, int $count = 1): CarbonInterface
    {
        return match ($this) {
            self::Day => $date->addDays($count),
            self::Week => $date->addWeeks($count),
            self::Month => $date->addMonths($count),
            self::Year => $date->addYears($count),
        };
    }
}
