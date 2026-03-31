<?php

namespace App\Repository;

use App\Entity\Request;
use App\Entity\WorkflowHistory;
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

    /**
     * @param array{status?: string, serviceId?: int, type?: string, arrivalDate?: string, departureDate?: string, agent?: string} $filters
     * @return list<Request>
     */
    public function findWithFilters(array $filters = [], int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->leftJoin('r.ressources', 're')->addSelect('re')
            ->orderBy('r.creationDate', 'ASC')
            ->setMaxResults($limit);

        if (!empty($filters['status'])) {
            $qb->andWhere('r.status = :status')->setParameter('status', $filters['status']);
        }

        if (!empty($filters['serviceId'])) {
            $qb->andWhere('s.id = :serviceId')->setParameter('serviceId', $filters['serviceId']);
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('r.type = :type')->setParameter('type', $filters['type']);
        }

        if (!empty($filters['arrivalDate'])) {
            $qb->andWhere('r.arrivalDate = :arrivalDate')
                ->setParameter('arrivalDate', new \DateTime($filters['arrivalDate']));
        }

        if (!empty($filters['departureDate'])) {
            $qb->andWhere('r.departureDate = :departureDate')
                ->setParameter('departureDate', new \DateTime($filters['departureDate']));
        }

        if (!empty($filters['agent'])) {
            $qb->andWhere('LOWER(a.firstname) LIKE :agent OR LOWER(a.lastname) LIKE :agent')
                ->setParameter('agent', '%' . mb_strtolower($filters['agent']) . '%');
        }

        /** @var list<Request> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * @return list<\DateTime>
     */
    public function findDistinctArrivalDates(): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('r.arrivalDate')
            ->where('r.arrivalDate IS NOT NULL')
            ->groupBy('r.arrivalDate')
            ->orderBy('r.arrivalDate', 'DESC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'arrivalDate');
    }

    /**
     * @return list<\DateTime>
     */
    public function findDistinctDepartureDates(): array
    {
        $result = $this->createQueryBuilder('r')
            ->select('r.departureDate')
            ->where('r.departureDate IS NOT NULL')
            ->groupBy('r.departureDate')
            ->orderBy('r.departureDate', 'DESC')
            ->getQuery()
            ->getResult();

        return array_column($result, 'departureDate');
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
            ->setParameter('statuses', ['validee', 'traitee'])
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
            ->setMaxResults($limit)
            ->orderBy('r.creationDate', 'ASC')
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
