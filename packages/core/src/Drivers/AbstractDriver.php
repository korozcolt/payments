<?php

declare(strict_types=1);

namespace Korbytes\Payments\Drivers;

use Carbon\CarbonImmutable;
use Korbytes\Payments\Contracts\PaymentDriverInterface;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Exceptions\PaymentException;

/**
 * Abstract base class for payment drivers.
 *
 * Provides common functionality shared across all payment drivers.
 */
abstract class AbstractDriver implements PaymentDriverInterface
{
    protected array $config = [];

    /**
     * Payout-specific credentials, separate from $config. Only populated
     * for drivers implementing PayoutDriverInterface, via configurePayouts().
     */
    protected array $payoutConfig = [];

    public function __construct(protected readonly DriverContext $ctx) {}

    public function configure(array $config): static
    {
        $this->config = $config;

        return $this;
    }

    /**
     * @see \Korbytes\Payments\Contracts\PayoutDriverInterface::configurePayouts()
     */
    public function configurePayouts(array $config): static
    {
        $this->payoutConfig = $config;

        return $this;
    }

    public function isConfigured(): bool
    {
        return ! empty($this->config);
    }

    public function isSandbox(): bool
    {
        return $this->config['sandbox'] ?? true;
    }

    /**
     * Log payment-related information.
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        if (! $this->ctx->settings->get('logging.enabled', true)) {
            return;
        }

        $context['provider'] = $this->getName();
        $context['sandbox'] = $this->isSandbox();

        $this->ctx->logger->{$level}("[Payments] {$message}", $context);
    }

    /**
     * Current time, from the injected clock.
     */
    protected function now(): CarbonImmutable
    {
        return CarbonImmutable::instance($this->ctx->clock->now());
    }

    /**
     * Read a payments setting (e.g. "urls.return").
     */
    protected function setting(string $key, mixed $default = null): mixed
    {
        return $this->ctx->settings->get($key, $default);
    }

    /**
     * Dispatch a framework-neutral event through PSR-14.
     *
     * @param  class-string  $event
     */
    protected function emit(string $event, mixed ...$arguments): void
    {
        $this->ctx->events->dispatch(new $event(...$arguments));
    }

    /**
     * Random RFC 4122 version 4 UUID.
     */
    protected function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }

    /**
     * Make an HTTP request to the provider's API.
     */
    protected function makeRequest(
        string $method,
        string $endpoint,
        array $data = [],
        array $headers = [],
        array $query = [],
    ): array {
        $baseUrl = $this->getBaseUrl();
        $url = rtrim($baseUrl, '/').'/'.ltrim($endpoint, '/');

        if (! empty($query)) {
            $url .= '?'.http_build_query($query);
        }

        $this->log('debug', 'Making API request', [
            'method' => $method,
            'url' => $url,
            'data' => $data,
        ]);

        $response = $this->ctx->http->withHeaders($headers)
            ->timeout(30)
            ->{strtolower($method)}($url, $data);

        $responseData = $response->json() ?? [];

        $this->log('debug', 'API response received', [
            'status' => $response->status(),
            'response' => $responseData,
        ]);

        if ($response->failed()) {
            throw PaymentException::apiError(
                provider: PaymentProvider::from($this->getName()),
                message: $responseData['error']['message'] ?? $responseData['message'] ?? 'API request failed',
                context: [
                    'status' => $response->status(),
                    'response' => $responseData,
                ],
            );
        }

        return $responseData;
    }

    /**
     * Get a configuration value.
     */
    protected function getConfig(string $key, mixed $default = null): mixed
    {
        return $this->config[$key] ?? $default;
    }

    /**
     * Get a payout-specific configuration value.
     */
    protected function getPayoutConfig(string $key, mixed $default = null): mixed
    {
        return $this->payoutConfig[$key] ?? $default;
    }

    /**
     * Generate a reference string for the transaction.
     */
    protected function generateReference(string $referenceId, int $transactionId): string
    {
        return "REF-{$referenceId}-TXN-{$transactionId}";
    }

    /**
     * Parse a reference string to extract the reference ID and transaction ID.
     *
     * @return array{reference_id: string|null, transaction_id: int|null}
     */
    protected function parseReference(string $reference): array
    {
        if (preg_match('/^REF-(.+)-TXN-(\d+)$/', $reference, $matches)) {
            return [
                'reference_id' => $matches[1],
                'transaction_id' => (int) $matches[2],
            ];
        }

        return [
            'reference_id' => null,
            'transaction_id' => null,
        ];
    }
}
