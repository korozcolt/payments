<?php

declare(strict_types=1);

namespace Korbytes\Payments\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\AsEncryptedArrayObject;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Korbytes\Payments\Enums\PaymentProvider;

/**
 * Payment gateway configuration model.
 *
 * @property int $id
 * @property PaymentProvider $provider
 * @property string $display_name
 * @property bool $is_active
 * @property bool $is_sandbox
 * @property \Illuminate\Database\Eloquent\Casts\ArrayObject|null $credentials
 * @property string|null $logo_url
 * @property string|null $description
 * @property int $sort_order
 * @property array|null $metadata
 */
class PaymentGateway extends Model
{
    protected $fillable = [
        'provider',
        'display_name',
        'is_active',
        'is_sandbox',
        'credentials',
        'logo_url',
        'description',
        'sort_order',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'provider' => PaymentProvider::class,
            'is_active' => 'boolean',
            'is_sandbox' => 'boolean',
            'credentials' => AsEncryptedArrayObject::class,
            'metadata' => 'array',
            'sort_order' => 'integer',
        ];
    }

    // Relationships

    public function transactions(): HasMany
    {
        return $this->hasMany(PaymentTransaction::class, 'provider', 'provider');
    }

    // Scopes

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeOrdered(Builder $query): Builder
    {
        return $query->orderBy('sort_order')->orderBy('display_name');
    }

    // Credential accessors for Wompi

    public function getPublicKeyAttribute(): ?string
    {
        return $this->credentials['public_key'] ?? null;
    }

    public function getPrivateKeyAttribute(): ?string
    {
        return $this->credentials['private_key'] ?? null;
    }

    public function getEventsSecretAttribute(): ?string
    {
        return $this->credentials['events_secret'] ?? null;
    }

    public function getIntegritySecretAttribute(): ?string
    {
        return $this->credentials['integrity_secret'] ?? null;
    }

    // Credential accessors for MercadoPago

    public function getAccessTokenAttribute(): ?string
    {
        return $this->credentials['access_token'] ?? null;
    }

    public function getWebhookSecretAttribute(): ?string
    {
        return $this->credentials['webhook_secret'] ?? null;
    }

    // Credential accessors for ePayco

    public function getClientIdAttribute(): ?string
    {
        return $this->credentials['p_cust_id_cliente'] ?? null;
    }

    public function getSecretKeyAttribute(): ?string
    {
        return $this->credentials['p_key'] ?? null;
    }

    // Helper methods

    /**
     * Check if the gateway is properly configured.
     */
    public function isConfigured(): bool
    {
        if ($this->credentials === null) {
            return false;
        }

        return match ($this->provider) {
            PaymentProvider::Wompi => isset($this->credentials['public_key'], $this->credentials['integrity_secret']),
            PaymentProvider::MercadoPago => isset($this->credentials['access_token']),
            PaymentProvider::Epayco => isset(
                $this->credentials['public_key'],
                $this->credentials['private_key'],
                $this->credentials['p_cust_id_cliente'],
                $this->credentials['p_key'],
            ),
        };
    }

    /**
     * Check if the gateway can process payments.
     */
    public function canProcess(): bool
    {
        return $this->is_active && $this->isConfigured();
    }

    /**
     * Get the base API URL for the provider.
     */
    public function getBaseUrl(): string
    {
        return match ($this->provider) {
            PaymentProvider::Wompi => $this->is_sandbox
                ? 'https://sandbox.wompi.co/v1'
                : 'https://production.wompi.co/v1',
            PaymentProvider::MercadoPago => 'https://api.mercadopago.com',
            PaymentProvider::Epayco => 'https://secure.epayco.co',
        };
    }

    /**
     * Get the widget/checkout URL for the provider.
     */
    public function getWidgetUrl(): string
    {
        return match ($this->provider) {
            PaymentProvider::Wompi => 'https://checkout.wompi.co/widget.js',
            PaymentProvider::MercadoPago => 'https://sdk.mercadopago.com/js/v2',
            PaymentProvider::Epayco => 'https://checkout.epayco.co/checkout.js',
        };
    }

    /**
     * Convert credentials to config array for driver.
     */
    public function toDriverConfig(): array
    {
        return array_merge(
            $this->credentials?->toArray() ?? [],
            [
                'sandbox' => $this->is_sandbox,
            ]
        );
    }
}
