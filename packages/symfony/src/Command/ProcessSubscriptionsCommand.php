<?php

declare(strict_types=1);

namespace Korbytes\Payments\Symfony\Command;

use Korbytes\Payments\Core\Standalone;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Charges due subscription cycles for providers without a billing engine (Wompi).
 * Schedule it, e.g. hourly: 0 * * * * php bin/console payments:process-subscriptions
 */
#[AsCommand(name: 'payments:process-subscriptions', description: 'Charge due subscription cycles for the configured providers.')]
final class ProcessSubscriptionsCommand extends Command
{
    public function __construct(private readonly Standalone $payments)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $scheduler = $this->payments->scheduler();

        if ($scheduler->providers() === []) {
            $io->writeln('No providers configured in payments.subscriptions.scheduled_providers - nothing to do.');

            return Command::SUCCESS;
        }

        $summary = $scheduler->processDue(function ($subscription, $result) use ($io) {
            if ($result->success) {
                $io->writeln("Charged subscription #{$subscription->id} ({$subscription->reference_id}).");
            } else {
                $io->error("Failed to charge subscription #{$subscription->id} ({$subscription->reference_id}): {$result->errorMessage}");
            }
        });

        if ($summary['charged'] + $summary['failed'] === 0) {
            $io->writeln('No due subscriptions found.');

            return Command::SUCCESS;
        }

        $io->writeln("Done. Charged: {$summary['charged']}, Failed: {$summary['failed']}.");

        return Command::SUCCESS;
    }
}
