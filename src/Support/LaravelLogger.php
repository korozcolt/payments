<?php

declare(strict_types=1);

namespace Korbytes\Payments\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\AbstractLogger;

/**
 * PSR-3 logger that writes to the channel configured in payments.logging.channel.
 * The channel is resolved per call so runtime config changes and log fakes are honoured.
 */
final class LaravelLogger extends AbstractLogger
{
    /**
     * $message stays untyped: psr/log 1.x declares it without a type, and a narrower override would fatal.
     *
     * @param  string|\Stringable  $message
     */
    public function log($level, $message, array $context = []): void
    {
        Log::channel(config('payments.logging.channel', 'stack'))->{(string) $level}((string) $message, $context);
    }
}
