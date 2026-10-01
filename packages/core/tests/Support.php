<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core\Tests;

use Korbytes\Payments\Drivers\DriverContext;
use Korbytes\Payments\Http\HttpClient;
use Korbytes\Payments\Support\ArrayConfig;
use Korbytes\Payments\Support\CallbackTransactionRunner;
use Korbytes\Payments\Support\SystemClock;
use Korbytes\Payments\Testing\InMemoryPayoutRepository;
use Korbytes\Payments\Testing\InMemorySubscriptionRepository;
use Korbytes\Payments\Testing\InMemoryTransactionRepository;
use Nyholm\Psr7\Factory\Psr17Factory;
use Nyholm\Psr7\Response;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Log\NullLogger;

/** PSR-18 client that records requests and replays queued responses. */
final class FakeClient implements ClientInterface
{
    /** @var array<int, RequestInterface> */
    public array $requests = [];

    /** @var array<int, ResponseInterface> */
    private array $queue = [];

    public function queue(int $status, array $json): self
    {
        $this->queue[] = new Response($status, ['Content-Type' => 'application/json'], json_encode($json));

        return $this;
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $this->requests[] = $request;

        return array_shift($this->queue) ?? new Response(200, [], '{}');
    }
}

/** PSR-14 dispatcher that records every event. */
final class RecordingDispatcher implements EventDispatcherInterface
{
    /** @var array<int, object> */
    public array $events = [];

    public function dispatch(object $event): object
    {
        $this->events[] = $event;

        return $event;
    }

    /** @return array<int, object> */
    public function of(string $class): array
    {
        return array_values(array_filter($this->events, fn ($e) => $e instanceof $class));
    }
}

final class Harness
{
    public FakeClient $client;

    public RecordingDispatcher $events;

    public DriverContext $context;

    public InMemoryTransactionRepository $transactions;

    public function __construct(array $config = [])
    {
        $factory = new Psr17Factory;
        $this->client = new FakeClient;
        $this->events = new RecordingDispatcher;
        $this->transactions = new InMemoryTransactionRepository;

        $clock = new SystemClock;

        $this->context = new DriverContext(
            http: new HttpClient($this->client, $factory, $factory),
            logger: new NullLogger,
            settings: new ArrayConfig($config + ['urls' => ['return' => 'https://shop.test/return']]),
            transactions: $this->transactions,
            subscriptions: new InMemorySubscriptionRepository($clock),
            payouts: new InMemoryPayoutRepository,
            runner: new CallbackTransactionRunner,
            events: $this->events,
            clock: $clock,
        );
    }
}
