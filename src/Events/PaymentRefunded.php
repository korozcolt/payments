<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\RefundResult;
use Korbytes\Payments\Models\PaymentTransaction;

/**
 * Event dispatched when a payment is successfully refunded via the
 * provider's API. Not dispatched for manual/unsupported refunds — see
 * RefundResult::notSupported().
 */
class PaymentRefunded
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly RefundResult $refundResult,
    ) {}
}
