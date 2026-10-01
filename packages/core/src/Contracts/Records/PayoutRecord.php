<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PayoutStatus;

/**
 * A persisted payout (payment to a third party).
 *
 * @property int $id
 * @property string $ulid
 * @property int $payout_beneficiary_id
 * @property string $reference_id
 * @property PaymentProvider $provider
 * @property string|null $provider_payout_id
 * @property int $amount
 * @property string $currency
 * @property PayoutStatus $status
 * @property string|null $description
 * @property array|null $provider_response
 * @property array|null $metadata
 * @property \Carbon\Carbon|null $processed_at
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
interface PayoutRecord extends Record {}
