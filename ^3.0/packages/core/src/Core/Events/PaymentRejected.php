<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core\Events;

use Korbytes\Payments\Contracts\Records\TransactionRecord;
use Korbytes\Payments\DTOs\WebhookResult;

/**
 * Framework-neutral event dispatched through PSR-14. Adapters may re-dispatch it as a
 * framework-native event (the Laravel adapter maps it to Korbytes\Payments\Events\PaymentRejected).
 */
final class PaymentRejected
{
    public function __construct(
        public readonly TransactionRecord $transaction,
        public readonly WebhookResult $webhookResult,
    ) {}
}
