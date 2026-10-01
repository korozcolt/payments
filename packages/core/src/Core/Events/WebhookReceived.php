<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core\Events;

use Korbytes\Payments\DTOs\WebhookResult;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * Framework-neutral event dispatched through PSR-14. Adapters may re-dispatch it as a
 * framework-native event (the Laravel adapter maps it to Korbytes\Payments\Events\WebhookReceived).
 */
final class WebhookReceived
{
    public function __construct(
        public readonly PaymentProvider $provider,
        public readonly WebhookResult $result,
        public readonly array $rawPayload,
    ) {}
}
