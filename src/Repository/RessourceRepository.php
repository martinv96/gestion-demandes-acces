<?php

namespace App\Repository;

use App\Entity\Ressource;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Ressource>
 */
class RessourceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Ressource::class);
    }

    //    /**
    //     * @return Ressource[] Returns an array of Ressource objects
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

    //    public function findOneBySomeField($value): ?Ressource
    //    {
    //        return $this->createQueryBuilder('r')
    //            ->andWhere('r.exampleField = :val')
    //            ->setParameter('val', $value)
    //            ->getQuery()
    //            ->getOneOrNullResult()
    //        ;
    //    }

    /**
     * pour retourner les logiciels paginés
     * @return Ressource[]
     */
    public function findPaginated(string $category, int $offset, $limit): array
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.category = :cat')
            ->setParameter('cat', $category)
            ->orderBy('r.name', 'ASC')
            ->setFirstResult($offset)
            ->setMaxResults($limit)
            ->getQuery()
            ->getResult();
    }

    /**
     * compte le nombre total de comptes (calcul du nombre de pages)
     */
    public function countAll(string $category):int
    {
        return $this->createQueryBuilder('r')
            ->select('count(r.id)')
            ->andWhere('r.category = :cat')
            ->setParameter('cat', $category)
            ->getQuery()
            ->getSingleScalarResult();
    }
}
