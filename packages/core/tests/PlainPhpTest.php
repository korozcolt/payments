<?php

declare(strict_types=1);

use Korbytes\Payments\Core\Events\PaymentApproved;
use Korbytes\Payments\Http\WebhookRequest;
use Korbytes\Payments\Support\EventDispatcher;

it('builds a WebhookRequest from superglobals with a JSON body', function () {
    $request = WebhookRequest::fromGlobals(
        server: ['HTTP_X_SIGNATURE' => 'sig', 'HTTP_X_REQUEST_ID' => 'r1', 'CONTENT_TYPE' => 'application/json', 'SERVER_NAME' => 'x'],
        query: ['page' => '2'],
        post: [],
        rawBody: '{"type":"payment","data":{"id":"123"}}',
    );

    expect($request->all()['type'])->toBe('payment')
        ->and($request->input('data.id'))->toBe('123')
        ->and($request->input('page'))->toBe('2')
        ->and($request->header('x-signature'))->toBe('sig')
        ->and($request->header('X-Request-Id'))->toBe('r1')
        ->and($request->header('content-type'))->toBe('application/json')
        ->and($request->getContent())->toBe('{"type":"payment","data":{"id":"123"}}');
});

it('falls back to form fields when the body is not JSON (ePayco style)', function () {
    $request = WebhookRequest::fromGlobals(
        server: [],
        query: [],
        post: ['x_ref_payco' => '55', 'x_signature' => 'abc'],
        rawBody: 'x_ref_payco=55&x_signature=abc',
    );

    expect($request->input('x_ref_payco'))->toBe('55')
        ->and($request->input('x_signature'))->toBe('abc');
});

it('dispatches events to listeners of the event class and its parents', function () {
    $events = new EventDispatcher;
    $seen = [];

    $events->listen(PaymentApproved::class, function ($e) use (&$seen) { $seen[] = 'approved'; });
    $events->listen(stdClass::class, function () use (&$seen) { $seen[] = 'never'; });

    $events->dispatch(new PaymentApproved(new Korbytes\Payments\Testing\ArrayRecord, Korbytes\Payments\DTOs\WebhookResult::notFound('x')));

    expect($seen)->toBe(['approved']);
});
