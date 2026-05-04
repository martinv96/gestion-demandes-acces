<?php

namespace App\Command;

use App\Service\Workflow\WorkflowReminderService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:workflow:send-reminders',
    description: 'envoie les relances automatiques des demandes bloquées.',
)]
final class WorkflowReminderCommand extends Command
{
    public function __construct(
        private WorkflowReminderService $workflowReminderService,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $count = $this->workflowReminderService->processAutomaticReminders();

        $io->success(sprintf('%d relance(s) ou escalade(s) envoyée(s).', $count));

        return Command::SUCCESS;
    }
}