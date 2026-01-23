<?php

declare(strict_types=1);

namespace Korbytes\Payments\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
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

    public function configure(array $config): static
    {
        $this->config = $config;

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
        if (! config('payments.logging.enabled', true)) {
            return;
        }

        $context['provider'] = $this->getName();
        $context['sandbox'] = $this->isSandbox();

        $channel = config('payments.logging.channel', 'stack');
        Log::channel($channel)->{$level}("[Payments] {$message}", $context);
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

        $response = Http::withHeaders($headers)
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
