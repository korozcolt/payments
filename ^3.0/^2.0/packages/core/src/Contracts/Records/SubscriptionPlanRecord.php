<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * A persisted subscription plan.
 *
 * @property int $id
 * @property string $ulid
 * @property PaymentProvider $provider
 * @property string|null $provider_plan_id
 * @property string $name
 * @property int $amount
 * @property string $currency
 * @property BillingInterval $interval
 * @property int $interval_count
 * @property int|null $trial_days
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
interface SubscriptionPlanRecord extends Record {}
