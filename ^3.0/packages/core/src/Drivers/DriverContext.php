<?php

declare(strict_types=1);

namespace Korbytes\Payments\Drivers;

use Korbytes\Payments\Contracts\ConfigProviderInterface;
use Korbytes\Payments\Contracts\Repositories\PayoutRepositoryInterface;
use Korbytes\Payments\Contracts\Repositories\SubscriptionRepositoryInterface;
use Korbytes\Payments\Contracts\Repositories\TransactionRepositoryInterface;
use Korbytes\Payments\Contracts\TransactionRunnerInterface;
use Korbytes\Payments\Http\HttpClient;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Log\LoggerInterface;

/**
 * Every collaborator a driver needs, bundled so drivers have a single
 * constructor argument and adapters wire things in one place.
 */
final class DriverContext
{
    public function __construct(
        public readonly HttpClient $http,
        public readonly LoggerInterface $logger,
        public readonly ConfigProviderInterface $settings,
        public readonly TransactionRepositoryInterface $transactions,
        public readonly SubscriptionRepositoryInterface $subscriptions,
        public readonly PayoutRepositoryInterface $payouts,
        public readonly TransactionRunnerInterface $runner,
        public readonly EventDispatcherInterface $events,
        public readonly ClockInterface $clock,
    ) {}
}
