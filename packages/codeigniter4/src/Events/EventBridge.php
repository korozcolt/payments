<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter\Events;

use CodeIgniter\Events\Events;
use Korbytes\Payments\Core\Events as Core;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Forwards the core's PSR-14 events to CodeIgniter's event system, so you
 * subscribe the CodeIgniter way (app/Config/Events.php):
 *
 *   Events::on('payments.approved', function (\Korbytes\Payments\Core\Events\PaymentApproved $event) {
 *       // $event->transaction->reference_id ...
 *   });
 */
final class EventBridge implements EventDispatcherInterface
{
    /** @var array<class-string, string> */
    public const NAMES = [
        Core\PaymentCreated::class => 'payments.created',
        Core\PaymentApproved::class => 'payments.approved',
        Core\PaymentRejected::class => 'payments.rejected',
        Core\PaymentRefunded::class => 'payments.refunded',
        Core\WebhookReceived::class => 'payments.webhook_received',
        Core\SubscriptionCreated::class => 'payments.subscription_created',
        Core\SubscriptionCancelled::class => 'payments.subscription_cancelled',
        Core\SubscriptionChargeSucceeded::class => 'payments.subscription_charge_succeeded',
        Core\SubscriptionChargeFailed::class => 'payments.subscription_charge_failed',
    ];

    public function dispatch(object $event): object
    {
        Events::trigger(self::NAMES[$event::class] ?? $event::class, $event);

        return $event;
    }
}
