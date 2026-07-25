<?php

declare(strict_types=1);

namespace Korbytes\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Enums\PaymentStatus;

/**
 * Payment transaction model.
 *
 * @property int $id
 * @property string $ulid
 * @property string|null $payable_type
 * @property int|null $payable_id
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
class PaymentTransaction extends Model
{
    protected $fillable = [
        'payable_type',
        'payable_id',
        'reference_id',
        'provider',
        'provider_transaction_id',
        'provider_reference',
        'provider_refund_id',
        'amount',
        'refunded_amount',
        'currency',
        'status',
        'idempotency_key',
        'webhook_received_at',
        'webhook_attempts',
        'provider_request',
        'provider_response',
        'webhook_payload',
        'error_code',
        'error_message',
        'metadata',
        'initiated_at',
        'completed_at',
        'refunded_at',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'amount' => 'integer',
            'refunded_amount' => 'integer',
            'status' => PaymentStatus::class,
            'webhook_received_at' => 'datetime',
            'webhook_attempts' => 'integer',
            'provider_request' => 'array',
            'provider_response' => 'array',
            'webhook_payload' => 'array',
            'metadata' => 'array',
            'initiated_at' => 'datetime',
            'completed_at' => 'datetime',
            'refunded_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PaymentTransaction $transaction) {
            $transaction->ulid ??= (string) Str::ulid();
            $transaction->idempotency_key ??= (string) Str::uuid();
        });
    }

    // Relationships

    /**
     * Get the payable model (Order or any other model).
     */
    public function payable(): BelongsTo
    {
        return $this->morphTo();
    }

    /**
     * Get the payment gateway.
     */
    public function gateway(): BelongsTo
    {
        return $this->belongsTo(PaymentGateway::class, 'provider', 'provider');
    }

    // Computed attributes

    public function getFormattedAmountAttribute(): string
    {
        return '$'.number_format($this->amount / 100, 0, ',', '.');
    }

    // Query scopes

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Pending);
    }

    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', PaymentStatus::Approved);
    }

    public function scopeForProvider(Builder $query, PaymentProvider $provider): Builder
    {
        return $query->where('provider', $provider);
    }

    public function scopeForReference(Builder $query, string $referenceId): Builder
    {
        return $query->where('reference_id', $referenceId);
    }

    // Business logic

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }

    public function isFinal(): bool
    {
        return $this->status->isFinal();
    }

    public function canProcess(): bool
    {
        return ! $this->isFinal();
    }
}
