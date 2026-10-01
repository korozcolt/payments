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
