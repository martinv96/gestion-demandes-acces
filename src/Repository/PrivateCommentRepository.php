<?php

namespace App\Repository;

use App\Entity\PrivateComment;
use App\Entity\Request;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PrivateComment>
 */
class PrivateCommentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PrivateComment::class);
    }

    public function save(PrivateComment $entity, bool $flush = false): void
    {
        $this->getEntityManager()->persist($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(PrivateComment $entity, bool $flush = false): void
    {
        $this->getEntityManager()->remove($entity);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Récupère les commentaires privés rattachés à une demande, ordonnés par date antéchronologique
     *
     * @return PrivateComment[]
     */
    public function findByRequest(Request $request): array
    {
        return $this->createQueryBuilder('p')
            ->andWhere('p.request = :request')
            ->setParameter('request', $request)
            ->orderBy('p.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }
}