<?php

namespace App\Repository;

use App\Entity\Agent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Agent>
 */
class AgentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Agent::class);
    }

    public function findOneByIdentity(string $firstname, string $lastname, ?string $email): ?Agent
    {
        $qb = $this->createQueryBuilder('a')
            ->andWhere('LOWER(a.firstname) = :firstname')
            ->andWhere('LOWER(a.lastname) = :lastname')
            ->setParameter('firstname', mb_strtolower(trim($firstname)))
            ->setParameter('lastname', mb_strtolower(trim($lastname)))
            ->setMaxResults(1);

        $normalizedEmail = $email !== null ? trim($email) : null;

        if ($normalizedEmail === null || $normalizedEmail === '') {
            $qb->andWhere('a.email IS NULL OR a.email = :emptyEmail')
                ->setParameter('emptyEmail', '');
        } else {
            $qb->andWhere('LOWER(a.email) = :email')
                ->setParameter('email', mb_strtolower($normalizedEmail));
        }

        return $qb->getQuery()->getOneOrNullResult();
    }

    //    /**
    //     * @return Agent[] Returns an array of Agent objects
    //     */
    //    public function findByExampleField($value): array
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->orderBy('a.id', 'ASC')
    //            ->setMaxResults(10)
    //            ->getQuery()
    //            ->getResult()
    //        ;
    //    }

    //    public function findOneBySomeField($value): ?Agent
    //    {
    //        return $this->createQueryBuilder('a')
    //            ->andWhere('a.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }
}
