<?php

declare(strict_types=1);

namespace Korbytes\Payments\Symfony\Command;

use Korbytes\Payments\Pdo\Schema;
use PDO;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Creates the payment tables (or prints the SQL for your migration tool with --dump).
 */
#[AsCommand(name: 'payments:schema', description: 'Create the payment tables, or print their SQL with --dump.')]
final class SchemaCommand extends Command
{
    public function __construct(private readonly PDO $pdo)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('dump', null, InputOption::VALUE_NONE, 'Print the SQL instead of executing it.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $dialect = Schema::dialectFor((string) $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME));
        $statements = Schema::statements($dialect);

        if ($input->getOption('dump')) {
            foreach ($statements as $statement) {
                $output->writeln($statement.';');
            }

            return Command::SUCCESS;
        }

        foreach ($statements as $statement) {
            $this->pdo->exec($statement);
        }

        $io->success(sprintf('Created %d statements (%s).', count($statements), $dialect));

        return Command::SUCCESS;
    }
}
