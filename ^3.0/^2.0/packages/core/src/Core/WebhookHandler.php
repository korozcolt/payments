<?php

declare(strict_types=1);

namespace Korbytes\Payments\Core;

use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Psr\Log\LoggerInterface;

/**
 * Framework-neutral webhook endpoint logic, shared by every adapter so that
 * Laravel, CodeIgniter, Symfony or plain PHP answer providers identically:
 *
 *   400  unknown or unavailable provider
 *   401  invalid signature
 *   200  processed; also 200 with success=false for known failures, so the
 *        provider does not retry forever
 *   500  unexpected error (the provider will retry)
 */
final class WebhookHandler
{
    public function __construct(
        private readonly PaymentManager $manager,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param  object  $request  WebhookRequest, PSR-7 server request, or an Illuminate-style request
     * @param  array<string, mixed>  $logContext  extra log context (e.g. the caller's IP)
     */
    public function handle(string $provider, object $request, array $logContext = []): WebhookResponse
    {
        $this->logger->info('[Payments] Webhook received', ['provider' => $provider] + $logContext);

        if (! PaymentProvider::tryFrom($provider)) {
            $this->logger->warning('[Payments] Invalid provider in webhook', ['provider' => $provider]);

            return new WebhookResponse(400, ['success' => false, 'error' => 'Invalid payment provider']);
        }

        if (! $this->manager->isAvailable($provider)) {
            $this->logger->warning('[Payments] Provider not available', ['provider' => $provider]);

            return new WebhookResponse(400, ['success' => false, 'error' => 'Payment provider not available']);
        }

        try {
            $driver = $this->manager->driver($provider);

            $driver->verifyWebhookSignature($request);

            $result = $driver->processWebhook($request);

            if ($result->success) {
                $this->logger->info('[Payments] Webhook processed successfully', [
                    'provider' => $provider,
                    'transaction_id' => $result->transaction?->getKey(),
                    'status' => $result->status?->value,
                ]);

                return new WebhookResponse(200, [
                    'success' => true,
                    'transaction_id' => $result->transaction?->getKey(),
                    'status' => $result->status?->value,
                ]);
            }

            $this->logger->warning('[Payments] Webhook processing failed', [
                'provider' => $provider,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ]);

            return new WebhookResponse(200, [
                'success' => false,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ]);
        } catch (InvalidWebhookSignatureException $e) {
            $this->logger->warning('[Payments] Invalid webhook signature', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return new WebhookResponse(401, ['success' => false, 'error' => 'Invalid webhook signature']);
        } catch (\Throwable $e) {
            $this->logger->error('[Payments] Webhook processing error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return new WebhookResponse(500, ['success' => false, 'error' => 'Internal server error']);
        }
    }
}
