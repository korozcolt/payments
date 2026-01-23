<?php

declare(strict_types=1);

namespace Korbytes\Payments\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * Event dispatched when a webhook is received from a payment provider.
 */
class WebhookReceived
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly WebhookResult $result,
        public readonly array $rawPayload,
    ) {}
}
