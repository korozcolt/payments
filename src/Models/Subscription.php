<?php

declare(strict_types=1);

namespace Korbytes\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\SubscriptionStatus;

/**
 * A recurring subscription for a customer to a plan.
 *
 * @property int $id
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
class Subscription extends Model
{
    protected $fillable = [
        'subscription_plan_id',
        'reference_id',
        'provider',
        'provider_subscription_id',
        'provider_payment_source_id',
        'customer_email',
        'customer_name',
        'customer_phone',
        'status',
        'trial_ends_at',
        'next_billing_date',
        'started_at',
        'cancelled_at',
        'last_charged_at',
        'failed_charge_attempts',
        'provider_response',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'status' => SubscriptionStatus::class,
            'trial_ends_at' => 'datetime',
            'next_billing_date' => 'datetime',
            'started_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'last_charged_at' => 'datetime',
            'failed_charge_attempts' => 'integer',
            'provider_response' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Subscription $subscription) {
            $subscription->ulid ??= (string) Str::ulid();
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPlan::class, 'subscription_plan_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class);
    }

    // Query scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->whereIn('status', [SubscriptionStatus::Active, SubscriptionStatus::Trialing]);
    }

    public function scopeDue(Builder $query): Builder
    {
        return $query->active()->where('next_billing_date', '<=', now());
    }

    public function scopeForProvider(Builder $query, PaymentProvider|string $provider): Builder
    {
        return $query->where('provider', $provider instanceof PaymentProvider ? $provider->value : $provider);
    }

    // Business logic

    public function isActive(): bool
    {
        return $this->status->isActive();
    }
}
