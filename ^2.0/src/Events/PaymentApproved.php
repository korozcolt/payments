<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Models\PaymentTransaction;

/**
 * Event dispatched when a payment is approved.
 *
 * Listen to this event to perform domain-specific actions
 * like updating order status, assigning tickets, etc.
 */
class PaymentApproved
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PaymentTransaction $transaction,
        public readonly WebhookResult $webhookResult,
    ) {}
}
