<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Models\PaymentTransaction;

/**
 * Event dispatched when a payment is rejected or fails.
 */
class PaymentRejected
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly WebhookResult $webhookResult,
    ) {}
}
