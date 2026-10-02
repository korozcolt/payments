<?php

declare(strict_types=1);

namespace Korbytes\Payments\Exceptions;

use Exception;

/**
 * Exception thrown when webhook signature verification fails.
 */
class InvalidWebhookSignatureException extends Exception
{
    public function __construct(
        string $message = 'Invalid webhook signature',
        public readonly ?string $expectedSignature = null,
        public readonly ?string $receivedSignature = null,
    ) {
        parent::__construct($message);
    }
}
