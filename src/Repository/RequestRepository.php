<?php

namespace App\Repository;

use App\Entity\Request;
use App\Entity\WorkflowHistory;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
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
     * @param array{status?: string, serviceId?: int, type?: string, arrivalDate?: string, departureDate?: string, agent?: string} $filters
     * @return list<Request>
     */
    public function findWithFilters(array $filters = [], int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->leftJoin('r.ressources', 're')->addSelect('re')
            ->leftJoin('r.childRequests', 'children')->addSelect('children')
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
    public function findDistinctCurrentArrivalDates(): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.arrivalDate')
            ->where('r.arrivalDate IS NOT NULL')
            ->groupBy('r.arrivalDate')
            ->orderBy('r.arrivalDate', 'DESC');

        $this->applyCurrentScope($qb);

        $result = $qb->getQuery()->getResult();

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

    /**
     * @return list<\DateTime>
     */
    public function findDistinctCurrentDepartureDates(): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.departureDate')
            ->where('r.departureDate IS NOT NULL')
            ->groupBy('r.departureDate')
            ->orderBy('r.departureDate', 'DESC');

        $this->applyCurrentScope($qb);

        $result = $qb->getQuery()->getResult();

        return array_column($result, 'departureDate');
    }

    public function countCurrent(): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)');

        $this->applyCurrentScope($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countPendingCurrent(): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status LIKE :pending')
            ->setParameter('pending', 'en_attente%');

        $this->applyCurrentScope($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    public function countProcessedCurrent(): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('statuses', ['traitee', 'refusee_rh', 'refusee_st', 'refusee_dsi']);

        $this->applyCurrentScope($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return list<Request>
     */

    public function findRecentCurrentForDashboard(int $limit = 5): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->setMaxResults($limit)
            ->orderBy('r.creationDate', 'ASC');

        $this->applyCurrentScope($qb);

        /** @var list<Request> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }

    /**
     * Page "Demandes" = état actuel :
     * - seule la dernière demande effective d'une chaîne parent/enfant est affichée
     * - une fermeture est affichée si elle est la dernière demande de la chaîne
     *
     * @param array{status?: string, serviceId?: int, type?: string, arrivalDate?: string, departureDate?: string, agent?: string} $filters
     * @return list<Request>
     */
    public function findCurrentWithFilters(array $filters = [], int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->leftJoin('r.ressources', 're')->addSelect('re')
            ->leftJoin('r.childRequests', 'children')->addSelect('children')
            ->orderBy('r.creationDate', 'ASC')
            ->setMaxResults($limit);

        $this->applyCurrentScope($qb);

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

    // Méthode privée pour appliquer le scope "état actuel" à une requête
    private function applyCurrentScope(QueryBuilder $qb): void
    {
        // Si une demande a une enfant MOD/FER traitée, elle est remplacée par la demande fille.
        $qb->andWhere(
            'NOT EXISTS (
                SELECT 1
                FROM App\Entity\Request child
                WHERE child.parentRequest = r
                  AND child.status = :currentProcessedStatus
                  AND child.type IN (:currentReplacementTypes)
            )'
        )
            ->setParameter('currentProcessedStatus', 'traitee')
            ->setParameter('currentReplacementTypes', ['modification', 'fermeture']);
    }

    public function findLatestProcessedReplacementChild(Request $parent): ?Request
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.parentRequest = :parent')
            ->andWhere('r.status = :status')
            ->andWhere('r.type IN (:types)')
            ->setParameter('parent', $parent)
            ->setParameter('status', 'traitee')
            ->setParameter('types', ['modification', 'fermeture'])
            ->orderBy('r.creationDate', 'DESC')
            ->addOrderBy('r.id', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    public function findCurrentInChain(Request $start): Request
    {
        $current = $start;

        while (true) {
            $next = $this->findLatestProcessedReplacementChild($current);
            if (!$next instanceof Request) {
                break;
            }
            $current = $next;
        }

        return $current;
    }

    public function findActiveCurrentRequestForAgentIdentity(
        string $firstname,
        string $lastname,
        string $email
    ): ?Request {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->andWhere('LOWER(a.firstname) = :firstname')
            ->andWhere('LOWER(a.lastname) = :lastname')
            ->andWhere('LOWER(a.email) = :email')
            ->orderBy('r.creationDate', 'DESC')
            ->setMaxResults(1)
            ->setParameter('firstname', mb_strtolower(trim($firstname)))
            ->setParameter('lastname', mb_strtolower(trim($lastname)))
            ->setParameter('email', mb_strtolower(trim($email)));

        // Scope courant (même logique que la page Demandes)
        $this->applyCurrentScope($qb);

        // "Chaîne active" = pas une fermeture traitée finale
        $qb->andWhere('NOT (r.type = :closedType AND r.status = :closedStatus)')
            ->setParameter('closedType', 'fermeture')
            ->setParameter('closedStatus', 'traitee');

        return $qb->getQuery()->getOneOrNullResult();
    }
}
