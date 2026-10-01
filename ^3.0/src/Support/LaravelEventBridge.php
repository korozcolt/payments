<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Illuminate\Support\Facades\Event;
use Korbytes\Payments\Core\Events as Core;
use Korbytes\Payments\Events as Laravel;
use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Re-dispatches the core's framework-neutral PSR-14 events as the Laravel
 * events applications already listen to (Dispatchable + SerializesModels), so
 * existing listeners, queued listeners and Event::fake() keep working untouched.
 */
final class LaravelEventBridge implements EventDispatcherInterface
{
    public function dispatch(object $event): object
    {
        $laravelEvent = match (true) {
            $event instanceof Core\PaymentApproved => new Laravel\PaymentApproved($event->transaction, $event->webhookResult),
            $event instanceof Core\PaymentRejected => new Laravel\PaymentRejected($event->transaction, $event->webhookResult),
            $event instanceof Core\PaymentCreated => new Laravel\PaymentCreated($event->transaction, $event->result),
            $event instanceof Core\PaymentRefunded => new Laravel\PaymentRefunded($event->transaction, $event->refundResult),
            $event instanceof Core\SubscriptionCancelled => new Laravel\SubscriptionCancelled($event->subscription, $event->subscriptionResult),
            $event instanceof Core\SubscriptionCreated => new Laravel\SubscriptionCreated($event->subscription, $event->subscriptionResult),
            $event instanceof Core\SubscriptionChargeFailed => new Laravel\SubscriptionChargeFailed($event->subscription, $event->paymentResult),
            $event instanceof Core\SubscriptionChargeSucceeded => new Laravel\SubscriptionChargeSucceeded($event->subscription, $event->paymentResult),
            $event instanceof Core\WebhookReceived => new Laravel\WebhookReceived($event->provider, $event->result, $event->rawPayload),
            default => $event,
        };

        Event::dispatch($laravelEvent);

        return $event;
    }
}
