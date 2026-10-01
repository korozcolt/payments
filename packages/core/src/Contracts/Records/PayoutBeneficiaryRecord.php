<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

use Korbytes\Payments\Enums\PaymentProvider;

/**
 * A persisted payout beneficiary.
 *
 * @property int $id
 * @property string $ulid
 * @property PaymentProvider $provider
 * @property string|null $provider_beneficiary_id
 * @property string $name
 * @property string $legal_id_type
 * @property string $legal_id
 * @property string $person_type
 * @property string $bank_code
 * @property string $account_type
 * @property string $account_number
 * @property string $category
 * @property string|null $email
 * @property string|null $phone
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
interface PayoutBeneficiaryRecord extends Record {}
