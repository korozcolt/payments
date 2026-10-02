<?php

declare(strict_types=1);

use Korbytes\Payments\Http\WebhookRequest;

it('exposes payload, query and case-insensitive headers', function () {
    $request = new WebhookRequest(
        payload: ['event' => 'transaction.updated'],
        headers: ['X-Signature' => 'abc', 'Accept' => ['a', 'b']],
        query: ['id' => '7'],
        rawBody: '{"event":"transaction.updated"}',
    );

    expect($request->all())->toBe(['event' => 'transaction.updated'])
        ->and($request->input('event'))->toBe('transaction.updated')
        ->and($request->input('id'))->toBe('7')
        ->and($request->input('nope', 'dflt'))->toBe('dflt')
        ->and($request->header('x-signature'))->toBe('abc')
        ->and($request->header('accept'))->toBe('a, b')
        ->and($request->header('missing'))->toBeNull()
        ->and($request->getContent())->toBe('{"event":"transaction.updated"}');
});

it('reads JSON and form-encoded PSR-7 bodies', function () {
    $factory = new Nyholm\Psr7\Factory\Psr17Factory;

    $json = $factory->createServerRequest('POST', 'https://x.test/hook?data.id=5')
        ->withHeader('X-Signature', 's')
        ->withBody($factory->createStream('{"a":{"b":1}}'));

    $form = $factory->createServerRequest('POST', 'https://x.test/hook')
        ->withHeader('Content-Type', 'application/x-www-form-urlencoded')
        ->withBody($factory->createStream('x_ref_payco=77&x_signature=zz'));

    $fromJson = WebhookRequest::fromPsr7($json->withQueryParams(['data.id' => '5']));
    $fromForm = WebhookRequest::fromPsr7($form);

    expect($fromJson->input('a.b'))->toBe(1)
        ->and($fromJson->input('data.id'))->toBe('5')
        ->and($fromJson->header('x-signature'))->toBe('s')
        ->and($fromJson->getContent())->toBe('{"a":{"b":1}}')
        ->and($fromForm->input('x_ref_payco'))->toBe('77')
        ->and($fromForm->input('x_signature'))->toBe('zz');
});
