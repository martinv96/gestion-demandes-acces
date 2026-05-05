<?php

namespace App\Service\Security;

use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;

final class LoginAuditRetentionService
{
    public function __construct(
        private Connection $connection,
        private ?LoggerInterface $logger = null,
    ) {
    }

    /**
     * @return array{aggregated_rows:int, purged_rows:int, cutoff:string}
     */
    public function purgeOlderThanMonths(int $months = 12): array
    {
        $cutoff = (new \DateTimeImmutable('first day of this month 00:00:00'))
            ->sub(new \DateInterval(sprintf('P%dM', $months)));

        $cutoffSql = $cutoff->format('Y-m-d H:i:s');

        $this->connection->beginTransaction();

        try {
            $aggregated = $this->connection->executeStatement(
                <<<SQL
INSERT INTO login_audit_daily_stat (stat_date, success_count, failure_count, logout_count, purged_count, updated_at)
SELECT
    DATE(occurred_at) AS stat_date,
    SUM(CASE WHEN event_type = 'success' THEN 1 ELSE 0 END) AS success_count,
    SUM(CASE WHEN event_type = 'failure' THEN 1 ELSE 0 END) AS failure_count,
    SUM(CASE WHEN event_type = 'logout' THEN 1 ELSE 0 END) AS logout_count,
    COUNT(*) AS purged_count,
    NOW() AS updated_at
FROM login_audit
WHERE occurred_at < :cutoff
GROUP BY DATE(occurred_at)
ON DUPLICATE KEY UPDATE
    success_count = success_count + VALUES(success_count),
    failure_count = failure_count + VALUES(failure_count),
    logout_count = logout_count + VALUES(logout_count),
    purged_count = purged_count + VALUES(purged_count),
    updated_at = VALUES(updated_at)
SQL,
                ['cutoff' => $cutoffSql]
            );

            $purged = $this->connection->executeStatement(
                'DELETE FROM login_audit WHERE occurred_at < :cutoff',
                ['cutoff' => $cutoffSql]
            );

            $this->connection->commit();

            $this->logger?->info('Purge audit connexions effectuée.', [
                'cutoff' => $cutoffSql,
                'aggregated_rows' => $aggregated,
                'purged_rows' => $purged,
            ]);

            return [
                'aggregated_rows' => (int) $aggregated,
                'purged_rows' => (int) $purged,
                'cutoff' => $cutoffSql,
            ];
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            $this->logger?->error('Échec purge audit connexions.', [
                'cutoff' => $cutoffSql,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }
}