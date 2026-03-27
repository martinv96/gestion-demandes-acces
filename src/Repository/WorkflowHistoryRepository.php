<?php

namespace App\Repository;

use App\Entity\WorkflowHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Request as AccessRequest;

/**
 * @extends ServiceEntityRepository<WorkflowHistory>
 */
class WorkflowHistoryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowHistory::class);
    }

    //    /**
    //     * @return WorkflowHistory[] Returns an array of WorkflowHistory objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('w.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?WorkflowHistory
    //    {
    //        return $this->createQueryBuilder('w')
    //            ->andWhere('w.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

  
public function findLatestByRequests(array $requests): array
{
    $requestIds = [];

    foreach ($requests as $request) {
        $id = $request->getId();
        if ($id !== null) {
            $requestIds[] = $id;
        }
    }

    if ($requestIds === []) {
        return [];
    }

    $histories = $this->createQueryBuilder('w')
        ->andWhere('IDENTITY(w.request) IN (:requestIds)')
        ->setParameter('requestIds', $requestIds)
        ->orderBy('w.request', 'ASC')
        ->addOrderBy('w.date', 'DESC')
        ->getQuery()
        ->getResult();

    $latestByRequestId = [];

    foreach ($histories as $history) {
        if (!$history instanceof WorkflowHistory) {
            continue;
        }

        $requestId = $history->getRequest()?->getId();
        if ($requestId === null) {
            continue;
        }

        if (!isset($latestByRequestId[$requestId])) {
            $latestByRequestId[$requestId] = $history;
        }
    }

    return $latestByRequestId;
}
}
