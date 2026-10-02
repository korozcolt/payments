<?php

declare(strict_types=1);

namespace Korbytes\Payments\CodeIgniter\Commands;

use CodeIgniter\CLI\BaseCommand;
use CodeIgniter\CLI\CLI;

/**
 * Charges due subscription cycles for providers without a billing engine
 * (payments.subscriptions.scheduled_providers). Add it to cron, e.g. hourly:
 *
 *   0 * * * * cd /path/to/app && php spark payments:process-subscriptions
 */
class ProcessSubscriptions extends BaseCommand
{
    protected $group = 'Payments';

    protected $name = 'payments:process-subscriptions';

    protected $description = 'Charge due subscription cycles for the configured providers.';

    public function run(array $params)
    {
        $scheduler = service('payments')->scheduler();

        if ($scheduler->providers() === []) {
            CLI::write('No providers configured in payments.subscriptions.scheduled_providers - nothing to do.');

            return EXIT_SUCCESS;
        }

        $summary = $scheduler->processDue(function ($subscription, $result) {
            if ($result->success) {
                CLI::write("Charged subscription #{$subscription->id} ({$subscription->reference_id}).", 'green');
            } else {
                CLI::error("Failed to charge subscription #{$subscription->id} ({$subscription->reference_id}): {$result->errorMessage}");
            }
        });

        if ($summary['charged'] + $summary['failed'] === 0) {
            CLI::write('No due subscriptions found.');

            return EXIT_SUCCESS;
        }

        CLI::write("Done. Charged: {$summary['charged']}, Failed: {$summary['failed']}.");

        return EXIT_SUCCESS;
    }
}
