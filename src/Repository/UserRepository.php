<?php

namespace App\Repository;

use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<User>
 */
class UserRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, User::class);
    }

    // ajout d'une méthode de controle des comptes actifs par role workflow
    public function hasActiveUserForWorkflowRole(string $workflowRole): bool
    {
        if(!str_starts_with($workflowRole, 'ROLE_')) {
            return false;
        }

        $serviceCode = strtolower(substr($workflowRole, strlen('ROLE_')));
        if ($serviceCode ==='') {
            return false;
        }

        return (int) $this->createQueryBuilder('u')
            ->select('COUNT(u.id)')
            ->leftJoin('u.service', 's')
            ->andWhere('u.isActive = :isActive')
            ->andWhere('LOWER(s.code) = :serviceCode')
            ->setParameter('isActive', true)
            ->setParameter('serviceCode', $serviceCode)
            ->getQuery()
            ->getSingleScalarResult() > 0;
    }
}
