<?php

namespace App\Command;

use App\Service\Contract\ContractEndReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\OutputStyle;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:contract:send-end-date-reminders',
    description: 'Envoie des rappels par mail pour les demandes dont la date de fin approche',
)]

final class ContractReminderCommand extends Command
{
    public function __construct(private ContractEndReminderService $service)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $count = $this->service->processUpcomingDepartures();

        $io->success(sprintf('$d rappel(s) envoyée(s).', $count));

        return Command::SUCCESS;
    }
}