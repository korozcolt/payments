<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core;

/**
 * What an adapter should answer to the payment provider: an HTTP status and a JSON-able body.
 */
final readonly class WebhookResponse
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function __construct(
        public int $status,
        public array $body,
    ) {}
}
