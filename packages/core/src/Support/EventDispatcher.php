<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Psr\EventDispatcher\EventDispatcherInterface;

/**
 * Tiny PSR-14 dispatcher for projects that do not already have one.
 *
 *   $events->listen(PaymentApproved::class, fn (PaymentApproved $e) => ...);
 *
 * Listeners registered for a parent class or interface also receive subclasses.
 * Any other PSR-14 dispatcher (Symfony, League, ...) can be used instead.
 */
final class EventDispatcher implements EventDispatcherInterface
{
    /** @var array<class-string, array<int, callable>> */
    private array $listeners = [];

    /**
     * @param  class-string  $event
     */
    public function listen(string $event, callable $listener): self
    {
        $this->listeners[$event][] = $listener;

        return $this;
    }

    public function dispatch(object $event): object
    {
        foreach ($this->listeners as $type => $listeners) {
            if ($event instanceof $type) {
                foreach ($listeners as $listener) {
                    $listener($event);
                }
            }
        }

        return $event;
    }
}
