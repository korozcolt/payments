<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\PaymentResult;
use Korbytes\Payments\Models\PaymentTransaction;

/**
 * Event dispatched when a payment intent is created.
 */
class PaymentCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly PaymentResult $result,
    ) {}
}
