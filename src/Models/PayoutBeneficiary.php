<?php

declare(strict_types=1);

namespace Korbytes\Payments\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * A payee/beneficiary for third-party payouts.
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
class PayoutBeneficiary extends Model
{
    protected $fillable = [
        'provider',
        'provider_beneficiary_id',
        'name',
        'legal_id_type',
        'legal_id',
        'person_type',
        'bank_code',
        'account_type',
        'account_number',
        'category',
        'email',
        'phone',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PayoutBeneficiary $beneficiary) {
            $beneficiary->ulid ??= (string) Str::ulid();
        });
    }

    public function payouts(): HasMany
    {
        return $this->hasMany(Payout::class);
    }
}
