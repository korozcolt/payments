<?php

declare(strict_types=1);

namespace Korbytes\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PayoutStatus;

/**
 * A payment sent to a third party (payout).
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
class Payout extends Model
{
    protected $fillable = [
        'payout_beneficiary_id',
        'reference_id',
        'provider',
        'provider_payout_id',
        'amount',
        'currency',
        'status',
        'description',
        'provider_response',
        'metadata',
        'processed_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount' => 'integer',
            'status' => PayoutStatus::class,
            'provider_response' => 'array',
            'metadata' => 'array',
            'processed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Payout $payout) {
            $payout->ulid ??= (string) Str::ulid();
        });
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(PayoutBeneficiary::class, 'payout_beneficiary_id');
    }

    public function scopeForProvider(Builder $query, PaymentProvider|string $provider): Builder
    {
        return $query->where('provider', $provider instanceof PaymentProvider ? $provider->value : $provider);
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }
}
