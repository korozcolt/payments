<?php

declare(strict_types=1);

namespace Korbytes\Payments\Console\Commands;

use Illuminate\Console\Command;
use Korbytes\Payments\Facades\Payments;
use Korbytes\Payments\Models\Subscription;

/**
 * Charges due subscription cycles for providers with no recurring-billing
 * engine of their own — see config('payments.subscriptions.scheduled_providers').
 *
 * This command does nothing on its own. It must be added to the HOST
 * application's own scheduler to actually run — see USAGE.md:
 *
 *   // routes/console.php (Laravel 11+)
 *   Schedule::command('payments:process-subscriptions')->hourly();
 */
class ProcessDueSubscriptionsCommand extends Command
{
    protected $signature = 'payments:process-subscriptions';

    protected $description = 'Charge due subscription cycles for providers configured in payments.subscriptions.scheduled_providers';

    public function handle(): int
    {
        $providers = config('payments.subscriptions.scheduled_providers', ['wompi']);

        if (empty($providers)) {
            $this->info('No providers configured in payments.subscriptions.scheduled_providers — nothing to do.');

            return self::SUCCESS;
        }

        $subscriptions = Subscription::due()->whereIn('provider', $providers)->get();

        if ($subscriptions->isEmpty()) {
            $this->info('No due subscriptions found.');

            return self::SUCCESS;
        }

        $charged = 0;
        $failed = 0;

        foreach ($subscriptions as $subscription) {
            $result = Payments::driver($subscription->provider->value)->chargeSubscriptionCycle($subscription);

            if ($result->success) {
                $charged++;
                $this->info("Charged subscription #{$subscription->id} ({$subscription->reference_id}).");
            } else {
                $failed++;
                $this->error("Failed to charge subscription #{$subscription->id} ({$subscription->reference_id}): {$result->errorMessage}");
            }
        }

        $this->info("Done. Charged: {$charged}, Failed: {$failed}.");

        return self::SUCCESS;
    }
}
