<?php

namespace App\Repository;

use App\Entity\Request;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Service\Workflow\WorkflowStateResolver;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Tools\Pagination\Paginator;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Request>
 */
class RequestRepository extends ServiceEntityRepository
{
    private WorkflowStateResolver $workflowStateResolver;

    public function __construct(ManagerRegistry $registry, WorkflowStateResolver $workflowStateResolver)
    {
        parent::__construct($registry, Request::class);
        $this->workflowStateResolver = $workflowStateResolver;
    }

    /**
     * @param array{status?: string, serviceId?: int, type?: string, arrivalDate?: string, departureDate?: string, agent?: string} $filters
     * @return list<Request>
     */
    public function findWithFilters(array $filters = [], int $limit = 10, int $offset = 0): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->orderBy('r.creationDate', 'DESC')
            ->setMaxResults($limit)
            ->setFirstResult($offset);

        if (!empty($filters['status'])) {
            $this->applyStatusFilter($qb, (string) $filters['status']);
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

        if (!empty($filters['status']) && $this->isSpecialValidatorStatus((string) $filters['status'])) {
            $results = $this->filterResultsByPendingValidatorRole($results, (string) $filters['status']);
        }

        return $results;
    }

    public function countWithFilters(array $filters = []): int 
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->leftJoin('r.agent', 'a')
            ->leftJoin('a.service', 's');

        if (!empty($filters['status'])) {
            $this->applyStatusFilter($qb, (string) $filters['status']);
        }

        if (!empty($filters['serviceId'])) {
            $qb->andWhere('s.id = :serviceId')->setParameter('serviceId', $filters['serviceId']);
        }

        if (!empty($filters['type'])) {
            $qb->andWhere('r.type = :type')->setParameter('type', $filters['type']);
        }

        if (!empty($filters['arrivalDate'])) {
            $qb->andWhere('r.arrivalDate = :arrivalDate')
                ->setParameter('arrivalDate', new \DateTime($filters['arrivalDate'])); //! protection injection exemple: new \DateTime ici sert de validation de type. tout ce qui n'est pas une date est filtrer et rejeté. ensuite, setParameter() requete préparer (prepared statement) symfony qui permet au serveur SQL d'analyser la requete. ça empeche le serveur SQL d'executer la requete comme si il s'agissait de code.
        }

        if (!empty($filters['departureDate'])) {
            $qb->andWhere('r.departureDate = :departureDate')
                ->setParameter('departureDate', new \DateTime($filters['departureDate']));
        }

        if (!empty($filters['agent'])) {
            $qb->andWhere('LOWER(a.firstname) LIKE :agent OR LOWER(a.lastname) LIKE :agent')
                ->setParameter('agent', '%' . mb_strtolower($filters['agent']) . '%');
        }

        if (!empty($filters['status']) && $this->isSpecialValidatorStatus((string) $filters['status'])) {
            $qb->select('r');
            /** @var list<Request> $results */
            $results = $qb->getQuery()->getResult();
            return count($this->filterResultsByPendingValidatorRole($results, (string) $filters['status']));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @param array{status?: string, serviceId?: int, type?: string, arrivalDate?: string, departureDate?: string, agent?: string} $filters
     */
    public function countCurrentWithFilters(array $filters = []): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->leftJoin('r.agent', 'a')
            ->leftJoin('a.service', 's');

        $this->applyCurrentScope($qb);

        if (!empty($filters['status'])) {
            $this->applyStatusFilter($qb, (string) $filters['status']);
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

        if (!empty($filters['status']) && $this->isSpecialValidatorStatus((string) $filters['status'])) {
            $qb->select('r');
            /** @var list<Request> $results */
            $results = $qb->getQuery()->getResult();
            return count($this->filterResultsByPendingValidatorRole($results, (string) $filters['status']));
        }

        return (int) $qb->getQuery()->getSingleScalarResult();
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
     * @return list<
     *     \DateTime
     * >
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
            ->setParameter('statuses', [Request::STATUS_TRAITEE, Request::STATUS_REFUSEE_RH, Request::STATUS_REFUSEE_ST, Request::STATUS_REFUSEE_DSI]);

        $this->applyCurrentScope($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * @return array<string, int>
     */
    public function countCurrentByType(): array
    {
        $qb = $this->createQueryBuilder('r')
            ->select('r.type AS requestType, COUNT(r.id) AS total')
            ->groupBy('r.type');

        $this->applyCurrentScope($qb);

        /** @var list<array{requestType: string, total: string}> $rows */
        $rows = $qb->getQuery()->getArrayResult();

        $result = [
            Request::TYPE_OUVERTURE => 0,
            Request::TYPE_MODIFICATION => 0,
            Request::TYPE_FERMETURE => 0,
        ];

        foreach ($rows as $row) {
            $type = $row['requestType'];
            if (!array_key_exists($type, $result)) {
                continue;
            }
            $result[$type] = (int) $row['total'];
        }

        return $result;
    }

    /**
     * @param list<string> $statuses
     */
    public function countCurrentCreatedInPeriod(\DateTimeInterface $start, \DateTimeInterface $end, array $statuses = []): int
    {
        $qb = $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->andWhere('r.creationDate >= :start')
            ->andWhere('r.creationDate < :end')
            ->setParameter('start', $start)
            ->setParameter('end', $end);

        if ($statuses !== []) {
            $qb
                ->andWhere('r.status IN (:statuses)')
                ->setParameter('statuses', $statuses);
        }

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
            ->orderBy('r.creationDate', 'DESC')
            ->addOrderBy('r.id', 'DESC');

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
    public function findCurrentWithFilters(
    array $filters = [], 
    int $limit = 100, 
    int $offset = 0, 
    string $sort = 'creationDate', 
    string $direction = 'DESC'
): array {
    $qb = $this->createQueryBuilder('r')
        ->leftJoin('r.agent', 'a')->addSelect('a')
        ->leftJoin('a.service', 's')->addSelect('s')
        ->setMaxResults($limit)
        ->setFirstResult($offset);

    // Gestion du tri dynamique
    switch ($sort) {
        case 'service':
            $qb->orderBy('s.name', $direction);
            break;
        case 'type':
            $qb->orderBy('r.type', $direction);
            break;
        case 'status':
            $qb->orderBy('r.status', $direction);
            break;
        case 'arrivalDate':
            $qb->orderBy('r.arrivalDate', $direction);
            break;
        case 'departureDate':
            $qb->orderBy('r.departureDate', $direction);
            break;
        case 'creationDate':
        default:
            $qb->orderBy('r.creationDate', $direction);
            break;
    }

    $this->applyCurrentScope($qb);

    if (!empty($filters['status'])) {
        $this->applyStatusFilter($qb, (string) $filters['status']);
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

    if (!empty($filters['status']) && $this->isSpecialValidatorStatus((string) $filters['status'])) {
        $results = $this->filterResultsByPendingValidatorRole($results, (string) $filters['status']);
    }

    return $results;
}
    /**
     * Requête optimisée pour l'export XLSX:
     * - pas de jointure childRequests (inutile pour l'export)
     * - conservation de agent/service/ressources pour alimenter les 2 onglets
     *
    * @param array{status?: string, serviceId?: int, type?: string, arrivalDate?: string, departureDate?: string, agent?: string} $filters
     *
     * @return list<Request>
     */
    public function findForExportWithFilters(array $filters = [], bool $historyScope = false, int $limit = 100): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->leftJoin('r.ressources', 're')->addSelect('re')
            ->orderBy('r.creationDate', 'DESC')
            ->setMaxResults($limit);

        if (!$historyScope) {
            $this->applyCurrentScope($qb);
        }

        if (!empty($filters['status'])) {
            $this->applyStatusFilter($qb, (string) $filters['status']);
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
        // "Etat actuel" = dernière demande de la chaîne parent/enfant (feuille).
        // Dès qu'une demande a une enfant (modification/fermeture), le parent bascule en historique.
        $qb->andWhere(
            'NOT EXISTS (
                SELECT 1
                FROM App\Entity\Request child
                WHERE child.parentRequest = r
            )'
        );
    }

    private function applyStatusFilter(QueryBuilder $qb, string $status): QueryBuilder
    {
        $status = trim($status);

        switch ($status) {
            case 'a_valider_rh':
                $qb->andWhere('r.status = :status')
                    ->setParameter('status', Request::STATUS_EN_ATTENTE_RH);
                break;
            case 'a_valider_st':
                $qb->andWhere('r.status IN (:statuses)')
                    ->setParameter('statuses', [Request::STATUS_EN_ATTENTE_VALIDATION, Request::STATUS_EN_ATTENTE_ST]);
                break;
            case 'a_valider_dsi':
                $qb->andWhere('r.status IN (:statuses)')
                    ->setParameter('statuses', [Request::STATUS_EN_ATTENTE_VALIDATION, Request::STATUS_EN_ATTENTE_DSI]);
                break;
            case 'a_valider_fin':
                $qb->andWhere('r.status = :status')
                    ->setParameter('status', Request::STATUS_EN_ATTENTE_VALIDATION);
                break;
            default:
                if ($status !== '') {
                    $qb->andWhere('r.status = :status')
                        ->setParameter('status', $status);
                }
                break;
        }

        return $qb;
    }

    public function findLatestProcessedReplacementChild(Request $parent): ?Request
    {
        return $this->createQueryBuilder('r')
            ->andWhere('r.parentRequest = :parent')
            ->andWhere('r.type IN (:types)')
            ->setParameter('parent', $parent)
            ->setParameter('types', [Request::TYPE_MODIFICATION, Request::TYPE_FERMETURE])
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
            ->setParameter('closedType', Request::TYPE_FERMETURE)
            ->setParameter('closedStatus', Request::STATUS_TRAITEE);

        return $qb->getQuery()->getOneOrNullResult();
    }

    private function isSpecialValidatorStatus(string $status): bool
    {
        return in_array($status, ['a_valider_st', 'a_valider_dsi', 'a_valider_fin'], true);
    }

    private function getRequiredValidatorRole(string $status): ?string
    {
        return match ($status) {
            'a_valider_st' => 'ROLE_ST',
            'a_valider_dsi' => 'ROLE_DSI',
            'a_valider_fin' => 'ROLE_FIN',
            default => null,
        };
    }

    /**
     * @param list<Request> $requests
     * @return list<Request>
     */
    private function filterResultsByPendingValidatorRole(array $requests, string $status): array
    {
        $requiredRole = $this->getRequiredValidatorRole($status);
        if ($requiredRole === null) {
            return $requests;
        }

        return array_values(array_filter($requests, function (Request $request) use ($requiredRole): bool {
            return in_array($requiredRole, $this->workflowStateResolver->getNextValidatorRoles($request), true);
        }));
    }

    /**
     * @return list<Request>
     */
    public function findPendingForAutomaticReminder(): array
    {
        /**@var list<Request> $results */
        $results = $this->createQueryBuilder('r')
            ->leftJoin('r.agent','a')->addSelect('a')
            ->leftJoin('a.service','s')->addSelect('s')
            ->andWhere('r.status IN (:statuses)')
            ->setParameter('statuses', [
                Request::STATUS_EN_ATTENTE_RH,
                Request::STATUS_EN_ATTENTE_VALIDATION,
                Request::STATUS_EN_ATTENTE_ST,
                Request::STATUS_EN_ATTENTE_DSI,
                Request::STATUS_EN_ATTENTE_TRAITEMENT,
            ])
            ->orderBy('r.updateDate','ASC')
            ->getQuery()
            ->getResult();

        return $results;
    }

    /** 
     * pour récupérer les demandes ayant une arrivée ou un départ
     * dans l'intervalle de dates visible sur le calendrier
     * 
     * @return list<Request>
     */
    public function findRequestsBetweenDates(\DateTimeInterface $start, \DateTimeInterface $end): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->andWhere('
                (r.arrivalDate >= :start AND r.arrivalDate <= :end) OR
                (r.departureDate >= :start AND r.departureDate <= :end)
            ')
            ->setParameter('start', $start)
            ->setParameter('end', $end)
            ->orderBy('r.arrivalDate', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return Request[]
     */
    public function findRequestByCreator(User $user): array
    {
        return $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')
            ->addSelect('a')
            ->where('r.author = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @param array{serviceId?: int|null, type?: string|null, agent?: string|null} $filters
     */
    public function findPaginatedRequestsByAuthor(User $user, int $page, int $limit, array $filters = []): Paginator
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')
            ->addSelect('a')
            ->leftJoin('a.service', 's')
            ->addSelect('s')
            ->where('r.author = :user')
            ->setParameter('user', $user)
            ->orderBy('r.id', 'DESC');

        if (!empty($filters['serviceId'])) {
            $qb
                ->andWhere('s.id = :serviceId')
                ->setParameter('serviceId', (int) $filters['serviceId']);
        }

        if (!empty($filters['type'])) {
            $qb
                ->andWhere('LOWER(r.type) = :type')
                ->setParameter('type', mb_strtolower((string) $filters['type']));
        }

        if (!empty($filters['agent'])) {
            $agentSearch = '%'.mb_strtolower(trim((string) $filters['agent'])).'%';
            $qb
                ->andWhere('LOWER(a.firstname) LIKE :agentSearch OR LOWER(a.lastname) LIKE :agentSearch')
                ->setParameter('agentSearch', $agentSearch);
        }

        $query = $qb
            ->setFirstResult(($page - 1) * $limit)
            ->setMaxResults($limit)
            ->getQuery();

        // Les jointures utilisées ici sont sur des relations to-one: on peut désactiver DISTINCT
        // pour éviter un COUNT très coûteux sur la pagination.
        $query->setHint(Paginator::HINT_ENABLE_DISTINCT, false);

        return new Paginator($query, false);
    }

    /**
     * @return list<Request>
     */
    public function findRequestsWithDepartureBetween(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $qb = $this->createQueryBuilder('r')
            ->leftJoin('r.agent', 'a')->addSelect('a')
            ->leftJoin('a.service', 's')->addSelect('s')
            ->andWhere('r.departureDate IS NOT NULL')
            ->andWhere('r.departureDate <= :to')
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->orderBy('r.departureDate', 'ASC');

        /**@var list<Request> $results */
        $results = $qb->getQuery()->getResult();

        return $results;
    }
}
