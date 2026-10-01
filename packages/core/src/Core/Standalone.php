<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core;

use Korbytes\Payments\Contracts\ConfigProviderInterface;
use Korbytes\Payments\Drivers\DriverContext;
use Korbytes\Payments\Http\HttpClient;
use Korbytes\Payments\Pdo\PdoPayoutRepository;
use Korbytes\Payments\Pdo\PdoStore;
use Korbytes\Payments\Pdo\PdoSubscriptionRepository;
use Korbytes\Payments\Pdo\PdoTransactionRepository;
use Korbytes\Payments\Pdo\PdoTransactionRunner;
use Korbytes\Payments\Support\ArrayConfig;
use Korbytes\Payments\Support\EventDispatcher;
use Korbytes\Payments\Support\SystemClock;
use PDO;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Batteries-included wiring for projects without a framework adapter:
 * a PSR-18 client + a PDO connection are all you need.
 *
 *   $payments = Standalone::pdo(
 *       config: ['default' => 'wompi', 'drivers' => ['wompi' => [...]]],
 *       pdo: $pdo,
 *       http: new GuzzleHttp\Client(['timeout' => 30]),
 *       factory: new GuzzleHttp\Psr7\HttpFactory,
 *   );
 *
 *   $payments->driver('wompi')->charge($paymentData);
 *   $payments->webhooks()->handle('wompi', WebhookRequest::fromGlobals());
 */
final class Standalone
{
    private function __construct(
        private readonly PaymentManager $manager,
        private readonly DriverContext $context,
        private readonly EventDispatcherInterface $events,
    ) {}

    /**
     * @param  array<string, mixed>|ConfigProviderInterface  $config  array shaped like config/payments.php
     * @param  RequestFactoryInterface&StreamFactoryInterface  $factory  any PSR-17 implementation that does both
     */
    public static function pdo(
        array|ConfigProviderInterface $config,
        PDO $pdo,
        ClientInterface $http,
        RequestFactoryInterface&StreamFactoryInterface $factory,
        ?LoggerInterface $logger = null,
        ?EventDispatcherInterface $events = null,
        ?ClockInterface $clock = null,
    ): self {
        $clock ??= new SystemClock;
        $events ??= new EventDispatcher;
        $store = new PdoStore($pdo, $clock);

        $context = new DriverContext(
            http: new HttpClient($http, $factory, $factory),
            logger: $logger ?? new NullLogger,
            settings: $config instanceof ConfigProviderInterface ? $config : new ArrayConfig($config),
            transactions: new PdoTransactionRepository($store),
            subscriptions: new PdoSubscriptionRepository($store),
            payouts: new PdoPayoutRepository($store),
            runner: new PdoTransactionRunner($pdo),
            events: $events,
            clock: $clock,
        );

        return new self(new PaymentManager($context), $context, $events);
    }

    public function manager(): PaymentManager
    {
        return $this->manager;
    }

    public function driver(?string $name = null): \Korbytes\Payments\Contracts\PaymentDriverInterface
    {
        return $this->manager->driver($name);
    }

    /**
     * Endpoint logic for your webhook route (see WebhookRequest::fromGlobals()).
     */
    public function webhooks(): WebhookHandler
    {
        return new WebhookHandler($this->manager, $this->context->logger);
    }

    /**
     * Call processDue() from cron to charge subscriptions of providers without a billing engine.
     */
    public function scheduler(): SubscriptionScheduler
    {
        return new SubscriptionScheduler($this->manager, $this->context->subscriptions, $this->context->settings);
    }

    /**
     * The PSR-14 dispatcher in use. When you did not pass your own, it is the built-in one
     * and you can register listeners: $payments->events()->listen(PaymentApproved::class, ...).
     */
    public function events(): EventDispatcherInterface
    {
        return $this->events;
    }
}
