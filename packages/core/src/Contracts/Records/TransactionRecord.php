<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;

/**
 * A persisted payment transaction.
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $payable_type
 * @property int|null $payable_id
 * @property int|null $subscription_id
 * @property string $reference_id
 * @property PaymentProvider $provider
 * @property string|null $provider_transaction_id
 * @property string|null $provider_reference
 * @property int $amount
 * @property string $currency
 * @property PaymentStatus $status
 * @property string $idempotency_key
 * @property \Carbon\Carbon|null $webhook_received_at
 * @property int $webhook_attempts
 * @property array|null $provider_request
 * @property array|null $provider_response
 * @property array|null $webhook_payload
 * @property string|null $error_code
 * @property string|null $error_message
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $initiated_at
 * @property \Carbon\Carbon|null $completed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
interface TransactionRecord extends Record
{
    /** @return bool */
    public function isSuccessful();

    /** @return bool */
    public function isFinal();

    /** @return bool */
    public function canProcess();
}
