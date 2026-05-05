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
}