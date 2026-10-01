<?php

declare(strict_types=1);

namespace Korbytes\Payments\Contracts\Records;

use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\SubscriptionStatus;

/**
 * A persisted subscription. Its plan is read through getAttribute('plan').
 *
 * @property int $id
 * @property SubscriptionPlanRecord $plan
 * @property string $ulid
 * @property int $subscription_plan_id
 * @property string $reference_id
 * @property PaymentProvider $provider
 * @property string|null $provider_subscription_id
 * @property string|null $provider_payment_source_id
 * @property string|null $customer_email
 * @property string|null $customer_name
 * @property string|null $customer_phone
 * @property SubscriptionStatus $status
 * @property \Carbon\Carbon|null $trial_ends_at
 * @property \Carbon\Carbon|null $next_billing_date
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $cancelled_at
 * @property \Carbon\Carbon|null $last_charged_at
 * @property int $failed_charge_attempts
 * @property array|null $provider_response
 * @property array|null $metadata
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 */
interface SubscriptionRecord extends Record {}
