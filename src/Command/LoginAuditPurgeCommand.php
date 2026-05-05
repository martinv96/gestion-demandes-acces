<?php

namespace App\Command;

use App\Service\Security\LoginAuditRetentionService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:login-audit:purge',
    description: 'Purge les audits de connexion > N mois en conservant des stats agrégées.'
)]
final class LoginAuditPurgeCommand extends Command
{
    public function __construct(
        private LoginAuditRetentionService $retentionService,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('months', null, InputOption::VALUE_REQUIRED, 'Rétention en mois', '12');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $months = max(1, (int) $input->getOption('months'));

        $result = $this->retentionService->purgeOlderThanMonths($months);

        $io->success(sprintf(
            'Purge terminée. Cutoff=%s, agrégés=%d, supprimés=%d',
            $result['cutoff'],
            $result['aggregated_rows'],
            $result['purged_rows']
        ));

        return Command::SUCCESS;
    }
}