<?php

declare(strict_types=1);

namespace Korbytes\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Korbytes\Payments\Enums\BillingInterval;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * Recurring billing plan.
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
class SubscriptionPlan extends Model
{
    protected $fillable = [
        'provider',
        'provider_plan_id',
        'name',
        'amount',
        'currency',
        'interval',
        'interval_count',
        'trial_days',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount' => 'integer',
            'interval' => BillingInterval::class,
            'interval_count' => 'integer',
            'trial_days' => 'integer',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (SubscriptionPlan $plan) {
            $plan->ulid ??= (string) Str::ulid();
        });
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }
}
