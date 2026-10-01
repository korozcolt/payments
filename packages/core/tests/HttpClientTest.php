<?php

declare(strict_types=1);

use Korbytes\Payments\Core\Tests\FakeClient;
use Korbytes\Payments\Http\HttpClient;
use Nyholm\Psr7\Factory\Psr17Factory;

require_once __DIR__.'/Support.php';

it('sends JSON bodies and custom headers over PSR-18 and parses the response', function () {
    $fake = (new FakeClient)->queue(201, ['id' => 'abc']);
    $factory = new Psr17Factory;

    $response = (new HttpClient($fake, $factory, $factory))
        ->withHeaders(['Authorization' => 'Bearer t'])
        ->timeout(30)
        ->post('https://api.test/payouts', ['amount' => 100]);

    $sent = $fake->requests[0];

    expect($sent->getMethod())->toBe('POST')
        ->and((string) $sent->getUri())->toBe('https://api.test/payouts')
        ->and($sent->getHeaderLine('Authorization'))->toBe('Bearer t')
        ->and($sent->getHeaderLine('Content-Type'))->toBe('application/json')
        ->and((string) $sent->getBody())->toBe('{"amount":100}')
        ->and($response->status())->toBe(201)
        ->and($response->successful())->toBeTrue()
        ->and($response->failed())->toBeFalse()
        ->and($response->json())->toBe(['id' => 'abc']);
});

it('appends GET data as a query string and flags error statuses as failed', function () {
    $fake = (new FakeClient)->queue(404, ['message' => 'nope']);
    $factory = new Psr17Factory;

    $response = (new HttpClient($fake, $factory, $factory))->get('https://api.test/x', ['a' => '1']);

    expect((string) $fake->requests[0]->getUri())->toBe('https://api.test/x?a=1')
        ->and((string) $fake->requests[0]->getBody())->toBe('')
        ->and($response->failed())->toBeTrue();
});
