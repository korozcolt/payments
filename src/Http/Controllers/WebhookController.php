<?php

declare(strict_types=1);

namespace Korbytes\Payments\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Korbytes\Payments\Enums\PaymentProvider;
use Korbytes\Payments\Exceptions\InvalidWebhookSignatureException;
use Korbytes\Payments\Facades\Payments;

/**
 * Controller for handling payment provider webhooks.
 */
class WebhookController extends Controller
{
    /**
     * Handle incoming webhook from a payment provider.
     */
    public function handle(Request $request, string $provider): JsonResponse
    {
        $logChannel = config('payments.logging.channel', 'stack');

        Log::channel($logChannel)->info('[Payments] Webhook received', [
            'provider' => $provider,
            'ip' => $request->ip(),
        ]);

        // Validate provider
        $providerEnum = PaymentProvider::tryFrom($provider);
        if (! $providerEnum) {
            Log::channel($logChannel)->warning('[Payments] Invalid provider in webhook', [
                'provider' => $provider,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Invalid payment provider',
            ], 400);
        }

        // Check if provider is enabled
        if (! Payments::isAvailable($provider)) {
            Log::channel($logChannel)->warning('[Payments] Provider not available', [
                'provider' => $provider,
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Payment provider not available',
            ], 400);
        }

        try {
            $driver = Payments::driver($provider);

            // Verify signature
            $driver->verifyWebhookSignature($request);

            // Process webhook
            $result = $driver->processWebhook($request);

            if ($result->success) {
                Log::channel($logChannel)->info('[Payments] Webhook processed successfully', [
                    'provider' => $provider,
                    'transaction_id' => $result->transaction?->id,
                    'status' => $result->status?->value,
                ]);

                return response()->json([
                    'success' => true,
                    'transaction_id' => $result->transaction?->id,
                    'status' => $result->status?->value,
                ]);
            }

            Log::channel($logChannel)->warning('[Payments] Webhook processing failed', [
                'provider' => $provider,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ]);

            return response()->json([
                'success' => false,
                'error_code' => $result->errorCode,
                'error_message' => $result->errorMessage,
            ], 200); // Return 200 to prevent provider retries for known failures

        } catch (InvalidWebhookSignatureException $e) {
            Log::channel($logChannel)->warning('[Payments] Invalid webhook signature', [
                'provider' => $provider,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Invalid webhook signature',
            ], 401);

        } catch (\Exception $e) {
            Log::channel($logChannel)->error('[Payments] Webhook processing error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'error' => 'Internal server error',
            ], 500);
        }
    }
}
