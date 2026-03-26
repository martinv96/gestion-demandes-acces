<?php

namespace App\Repository;

use App\Entity\Request;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Request>
 */
class RequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Request::class);
    }

    /**
     * @return list<Request>
     */
    public function findLatestWithRelations(int $limit = 100): array
    {
        /** @var list<Request> $results */
        $results = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->leftJoin('r.ressources', 're')->addSelect('re')
            ->orderBy('r.creationDate', 'ASC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }

    public function countPendingRequests(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status LIKE :pending')
            ->setParameter('pending', 'en_attente%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    public function countProcessedRequests(): int
    {
        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('statuses', ['validee', 'traitee', 'terminee'])
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * @return list<Request>
     */
    public function findRecentForDashboard(int $limit = 5): array
    {
        /** @var list<Request> $results */
        $results = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->orderBy('r.creationDate', 'DESC')
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();

        return $results;
    }

    public function getDisplayNumber(Request $request): int
    {
        $createdAt = $request->getCreationDate();
        $id = $request->getId();

        if ($createdAt === null || $id === null) {
            return 1;
        }

        $countNewer = (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.creationDate > :createdAt OR (r.creationDate = :createdAt AND r.id > :id)')
            ->setParameter('createdAt', $createdAt)
            ->setParameter('id', $id)
            ->getQuery()
            ->getSingleScalarResult();

        return $countNewer + 1;
    }

    //    /**
    //     * @return Request[] Returns an array of Request objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('r.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Request
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
