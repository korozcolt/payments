<?php

declare(strict_types=1);

namespace Korbytes\Payments\Console\Commands;

use Illuminate\Console\Command;
use Korbytes\Payments\Core\SubscriptionScheduler;

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

    public function handle(SubscriptionScheduler $scheduler): int
    {
        if ($scheduler->providers() === []) {
            $this->info('No providers configured in payments.subscriptions.scheduled_providers — nothing to do.');

            return self::SUCCESS;
        }

        $summary = $scheduler->processDue(function ($subscription, $result) {
            if ($result->success) {
                $this->info("Charged subscription #{$subscription->id} ({$subscription->reference_id}).");
            } else {
                $this->error("Failed to charge subscription #{$subscription->id} ({$subscription->reference_id}): {$result->errorMessage}");
            }
        });

        if ($summary['charged'] + $summary['failed'] === 0) {
            $this->info('No due subscriptions found.');

            return self::SUCCESS;
        }

        $this->info("Done. Charged: {$summary['charged']}, Failed: {$summary['failed']}.");

        return self::SUCCESS;
    }
}
