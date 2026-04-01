<?php

namespace App\Repository;

use App\Entity\WorkflowTransitionConfig;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<WorkflowTransitionConfig>
 */
class WorkflowTransitionConfigRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, WorkflowTransitionConfig::class);
    }

   /**
    * @return list<WorkflowTransitionConfig>
    */

   public function findActiveTransitionsForWorkflow(string $workflowCode): array
   {
       /** @var list<WorkflowTransitionConfig> */
       $results = $this->createQueryBuilder('w')
           ->andWhere('w.workflowCode = :workflowCode')
           ->andWhere('w.isActive = :isActive')
           ->setParameter('workflowCode', $workflowCode)
           ->setParameter('isActive', true)
           ->orderBy('w.stepOrder', 'ASC')
           ->addOrderBy('w.action', 'ASC')
           ->getQuery()
           ->getResult();

        return $results;
   }
}
