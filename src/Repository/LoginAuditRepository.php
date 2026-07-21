<?php

namespace App\Repository;

use App\Entity\LoginAudit;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<LoginAudit>
 */
class LoginAuditRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, LoginAudit::class);
    }

    /**
     * @return list<LoginAudit>
     */
    public function findRecent(int $limit = 100): array
    {
        /** @var list<LoginAudit> $rows */
        $rows = $this->createQueryBuilder('a')
            ->leftJoin('a.user', 'u')->addSelect('u')
            ->orderBy('a.occurredAt', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $rows;
    }

    public function countAll(): int
{
    return (int) $this->createQueryBuilder('a')
        ->select('COUNT(a.id)')
        ->getQuery()
        ->getSingleScalarResult();
}

/**
 * @return list<LoginAudit>
 */
public function findPaginated(int $offset, int $limit): array
{
    /** @var list<LoginAudit> $rows */
    $rows = $this->createQueryBuilder('a')
        ->leftJoin('a.user', 'u')->addSelect('u')
        ->orderBy('a.occurredAt', 'DESC')
        ->setFirstResult($offset)
        ->setMaxResults($limit)
        ->getQuery()
        ->getResult();

    return $rows;
}

    public function countByEventType(string $eventType): int
    {
        return (int) $this->createQueryBuilder('a')
            ->select('COUNT(a.id)')
            ->andWhere('a.eventType = :eventType')
            ->setParameter('eventType', $eventType)
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countWithFilters(array $filters): int
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COUNT(a.id)');

        $this->applyFilters($qb, $filters);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function getTotalsForPeriod(array $filters): array
    {
        $qb = $this->createQueryBuilder('a')
            ->select('COALESCE(SUM(CASE WHEN a.eventType = :success THEN 1 ELSE 0 END), 0) AS success')
            ->addSelect('COALESCE(SUM(CASE WHEN a.eventType = :failure THEN 1 ELSE 0 END), 0) AS failure')
            ->addSelect('COALESCE(SUM(CASE WHEN a.eventType = :logout THEN 1 ELSE 0 END), 0) AS logout')
            ->setParameter('success', LoginAudit::EVENT_SUCCESS)
            ->setParameter('failure', LoginAudit::EVENT_FAILURE)
            ->setParameter('logout', LoginAudit::EVENT_LOGOUT);

        $this->applyFilters($qb, $filters);

        $row = $qb->getQuery()->getSingleResult();

        return [
            'success' => (int) ($row['success'] ?? 0),
            'failure' => (int) ($row['failure'] ?? 0),
            'logout' => (int) ($row['logout'] ?? 0),
        ];
    }

    /**
     * @return list<LoginAudit>
     */
    public function findPaginatedWithFilters(array $filters, int $offset, int $limit): array
    {
    $qb = $this->createQueryBuilder('a')
        ->leftJoin('a.user', 'u')->addSelect('u')
        ->orderBy('a.occurredAt', 'DESC')
        ->setFirstResult($offset)
        ->setMaxResults($limit);

    $this->applyFilters($qb, $filters);

    /** @var list<LoginAudit> $rows */
    $rows = $qb->getQuery()->getResult();

    return $rows;
}

private function applyFilters(\Doctrine\ORM\QueryBuilder $qb, array $filters): void
{
    if (!empty($filters['eventType'])) {
        $qb->andWhere('a.eventType = :eventType')
           ->setParameter('eventType', (string) $filters['eventType']);
    }

    if (!empty($filters['dateFrom']) && $filters['dateFrom'] instanceof \DateTimeInterface) {
        $qb->andWhere('a.occurredAt >= :dateFrom')
           ->setParameter('dateFrom', $filters['dateFrom']);
    }

    if (!empty($filters['dateTo']) && $filters['dateTo'] instanceof \DateTimeInterface) {
        $qb->andWhere('a.occurredAt < :dateTo')
           ->setParameter('dateTo', $filters['dateTo']);
    }
}
}