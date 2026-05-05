<?php

namespace App\Repository;

use App\Entity\LoginAuditDailyStat;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

class LoginAuditDailyStatRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginAuditDailyStat::class);
    }

    /**
     * @return array{success: int, failure: int, logout: int, purged: int}
     */
    public function getTotals(): array
    {
        $row = $this->createQueryBuilder('s')
            ->select('COALESCE(SUM(s.successCount), 0) AS success')
            ->addSelect('COALESCE(SUM(s.failureCount), 0) AS failure')
            ->addSelect('COALESCE(SUM(s.logoutCount), 0) AS logout')
            ->addSelect('COALESCE(SUM(s.purgedCount), 0) AS purged')
            ->getQuery()
            ->getSingleResult();

        return [
            'success' => (int) ($row['success'] ?? 0),
            'failure' => (int) ($row['failure'] ?? 0),
            'logout' => (int) ($row['logout'] ?? 0),
            'purged' => (int) ($row['purged'] ?? 0),
        ];
    }

    /**
     * @return list<LoginAuditDailyStat>
     */
    public function findRecentDays(int $limit = 30): array
    {
        /** @var list<LoginAuditDailyStat> $rows */
        $rows = $this->createQueryBuilder('s')
            ->orderBy('s.statDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }
}