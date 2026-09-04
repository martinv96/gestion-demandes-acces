<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\User;
use App\Message\WorkflowNotificationMessage;
use App\Repository\RessourceRepository;
use App\Repository\RequestRepository;
use App\Repository\ServiceRepository;
use App\Repository\PrivateCommentRepository;
use App\Service\RequestUpdateInfoService;
use App\Service\WorkflowService;
use App\Security\Voter\RequestVoter;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request as HttpRequest;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;




/**
 * Regroupe les pages de consultation des demandes et les actions du workflow.
 * Les actions autonomes de fermeture, export, suppression et notes privées ont été déplacées
 * dans des contrôleurs dédiés afin de garder ce contrôleur centré sur la liste et le détail.
 */
final class ListRequestController extends AbstractController
{
    // route pour afficher la liste des demandes
    // ! route qui affiche la liste de toutes les demandes d'accès (avec status type et date)
    // ! + un lien vers la page de détail de chaque demande
    #[Route('/list/request', name: 'app_list_request', methods: ['GET'])]
    public function index(RequestRepository $requestRepository, ServiceRepository $serviceRepository, HttpRequest $httpRequest): Response
    {
        // Pagination de la liste principale : 15 demandes affichées par page.
        $limit = 15;
        $page = $httpRequest->query->getInt('page', 1);
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;

        // ! gestion des filtres de recherche : status, service, type et date d'arrivée
        $allowedStatuses = array_merge(AccessRequest::WORKFLOW_STATUSES, [
            'a_valider_rh',
            'a_valider_st',
            'a_valider_dsi',
            'a_valider_fin',
        ]);
        $allowedTypes = AccessRequest::TYPES;

        $status        = (string) $httpRequest->query->get('status', '');
        $serviceId     = (string) $httpRequest->query->get('serviceId', '');
        $type          = (string) $httpRequest->query->get('type', '');
        $arrivalDate   = (string) $httpRequest->query->get('arrivalDate', '');
        $departureDate = (string) $httpRequest->query->get('departureDate', '');
        $agent         = trim((string) $httpRequest->query->get('agent', ''));

        // Le tri vient de l'URL : seules les colonnes et directions listées ci-dessous sont acceptées.
        $sort = (string) $httpRequest->query->get('sort', 'creationDate');
        $direction = strtoupper((string) $httpRequest->query->get('direction', 'DESC'));

        // Sécurité sur la direction
        if (!in_array($direction, ['ASC', 'DESC'], true)) {
            $direction = 'DESC';
        }

        // Sécurité sur la colonne autorisée
        $allowedSorts = ['service', 'arrivalDate', 'departureDate', 'creationDate', 'type', 'status'];
        if (!in_array($sort, $allowedSorts, true)) {
            $sort = 'creationDate';
        }

        // Seules les valeurs validées sont transmises au repository pour construire la requête SQL.
        $filters = [];

        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $filters['status'] = $status;
        } else {
            $status = '';
        }

        if ($serviceId !== '' && ctype_digit($serviceId)) {
            $filters['serviceId'] = (int) $serviceId;
        } else {
            $serviceId = '';
        }

        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $filters['type'] = $type;
        } else {
            $type = '';
        }

        if ($arrivalDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrivalDate)) {
            $filters['arrivalDate'] = $arrivalDate;
        } else {
            $arrivalDate = '';
        }

        if ($departureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate)) {
            $filters['departureDate'] = $departureDate;
        } else {
            $departureDate = '';
        }

        if ($agent !== '' && mb_strlen($agent) <= 100) {
            $filters['agent'] = $agent;
        } else {
            $agent = '';
        }

        $requests = $requestRepository->findCurrentWithFilters($filters, $limit, $offset, $sort, $direction);
        $services            = $serviceRepository->findBy([], ['name' => 'DESC']);
        $availableDates      = $requestRepository->findDistinctCurrentArrivalDates();
        $availableDepartures = $requestRepository->findDistinctCurrentDepartureDates();

        $totalCount          = $requestRepository->countCurrent();
        $totalWithFilters    = $requestRepository->countCurrentWithFilters($filters);
        $maxPages            = ceil($totalWithFilters / $limit);
        $pagesCount          = max(1, (int) ceil($totalWithFilters / $limit));


        // La vue reçoit à la fois les résultats, les choix de filtre et les données de pagination.
        return $this->render('list_request/index.html.twig', [
            'requests'            => $requests,
            'services'            => $services,
            'availableDates'      => $availableDates,
            'availableDepartures' => $availableDepartures,
            'currentPage'         => $page,
            'pagesCount'          => $pagesCount,
            'maxPages'            => $maxPages,
            'totalCount'          => $totalCount,
            'filters'             => [
                'status'        => $status,
                'serviceId'     => $serviceId,
                'type'          => $type,
                'arrivalDate'   => $arrivalDate,
                'departureDate' => $departureDate,
                'agent'         => $agent,
                'sort'          => $sort,
                'direction'     => $direction,
            ],
            'exportScope' => 'current',
            'pageRoute'   => 'app_list_request',
        ]);
    }

    // route pour afficher les détails d'une demande
    // ! route qui affiche les détails d'une demande d'accès spécifique
    #[Route('/request/{id}', name: 'app_request_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(
        AccessRequest $requestEntity,
        WorkflowService $workflowService,
        \App\Service\Workflow\WorkflowStateResolver $workflowStateResolver,
        RessourceRepository $ressourceRepository,
        ServiceRepository $serviceRepository,
        PrivateCommentRepository $privateCommentRepository
    ): Response {
        // Cette action prépare toutes les données nécessaires à la page de détail Twig.
        // Les changements d'état restent dans les actions POST plus bas dans ce contrôleur.
        $canEditRequestInfo = $this->isGranted(RequestVoter::EDIT_INFO, $requestEntity);
        $canUndo = $this->isGranted(RequestVoter::UNDO, $requestEntity);
        $canUnblock = $this->isGranted(RequestVoter::UNBLOCK, $requestEntity);
        $isBlockedByMissingValidator = $workflowService->isBlockedByMissingValidator($requestEntity);
        $missingValidatorLabel = $workflowService->getMissingValidatorLabel($requestEntity);
        $allLogiciels = $ressourceRepository->findBy(['category' => 'logiciel', 'isActive' => true], ['name' => 'ASC']);
        $allMateriels = $ressourceRepository->findBy(['category' => 'materiel', 'isActive' => true], ['name' => 'ASC']);
        $closureTrackedMateriels = [];
        $closurePendingMateriels = [];
        $closureReturnedMateriels = [];
        $closureUntrackedMateriels = [];

        // Les listes de restitution sont filtrées selon le service de l'utilisateur connecté.
        $currentUser = $this->getUser();

        if ($requestEntity->getType() === AccessRequest::TYPE_FERMETURE && $requestEntity->getParentRequest() instanceof AccessRequest) {
            // Une fermeture compare ses ressources avec celles de la demande d'origine.
            $parentMateriels = array_values(array_filter(
                $requestEntity->getParentRequest()->getRessources()->toArray(),
                static fn($r) => $r instanceof Ressource && $r->getCategory() === 'materiel'
            ));

            // Les matériels encore liés à la fermeture sont ceux qui restent à restituer.
            $pendingIds = [];
            foreach ($requestEntity->getRessources() as $r) {
                if ($r->getCategory() === 'materiel' && $r->getId() !== null) {
                    $pendingIds[] = $r->getId();
                }
            }

            foreach ($parentMateriels as $materiel) {
                if ($currentUser instanceof User && !$this->canViewClosureMaterial($materiel, $currentUser)) {
                    continue;
                }

                // Les matériels visibles sont séparés en "à remettre" et "déjà remis" pour Twig.
                $closureTrackedMateriels[] = $materiel;
                if ($materiel->getId() !== null && in_array($materiel->getId(), $pendingIds, true)) {
                    $closurePendingMateriels[] = $materiel;
                } else {
                    $closureReturnedMateriels[] = $materiel;
                }
            }

            // Les matériels actifs non liés à cette fermeture sont affichés comme non concernés.
            $trackedIds = array_values(array_filter(array_map(static fn($m) => $m->getId(), $closureTrackedMateriels)));
            foreach ($allMateriels as $materiel) {
                if ($currentUser instanceof User && !$this->canViewClosureMaterial($materiel, $currentUser)) {
                    continue;
                }

                if (!in_array($materiel->getId(), $trackedIds, true)) {
                    $closureUntrackedMateriels[] = $materiel;
                }
            }
        }

        // Un matériel ne peut être basculé qu'avant la clôture définitive de la demande.
        $canMarkReturned = $requestEntity->getType() === AccessRequest::TYPE_FERMETURE
            && $requestEntity->getStatus() !== AccessRequest::STATUS_TRAITEE;
        $canFinalizeClosure = $workflowService->canFinalizeClosureByAnyUser($requestEntity);

        // Ces tableaux évitent de recalculer les droits pour chaque ligne dans le template.
        $closureManageableMaterielIds = [];
        $closureOwnerById = [];

        if ($currentUser instanceof User) {
            foreach ($closureTrackedMateriels as $materiel) {
                $id = $materiel->getId();
                if ($id === null) {
                    continue;
                }

                $ownerRole = $this->resolveClosureOwnerRole($materiel);
                $closureOwnerById[$id] = match ($ownerRole) {
                    'ROLE_DSI' => 'DSI',
                    'ROLE_ST' => 'Service technique',
                    default => 'RH',
                };

                if ($this->canManageClosureMaterial($materiel, $currentUser)) {
                    $closureManageableMaterielIds[] = $id;
                }
            }
        }

        // Un utilisateur ayant déjà validé ne peut pas modifier les informations dans le même cycle.
        $currentUserHasValidated = false;
        if ($currentUser instanceof User) {
            foreach ($requestEntity->getRequestId() as $history) {
                $historyComment = (string) ($history->getCommentary() ?? '');
                if (str_starts_with($historyComment, 'Modification des informations :')) {
                    continue;
                }

                if (
                    $history->getUser() &&
                    $history->getUser()->getId() === $currentUser->getId() &&
                    in_array($history->getNewStatus(), [
                        AccessRequest::STATUS_EN_ATTENTE_ST,
                        AccessRequest::STATUS_EN_ATTENTE_DSI,
                        AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT,
                        AccessRequest::STATUS_TRAITEE,
                        AccessRequest::STATUS_REFUSEE_RH,
                        AccessRequest::STATUS_REFUSEE_ST,
                        AccessRequest::STATUS_REFUSEE_DSI
                    ], true)
                ) {
                    $currentUserHasValidated = true;
                    break;
                }
            }
        }
        $isInfoEditLocked = $currentUser instanceof User
            ? $workflowService->isInfoEditLocked($requestEntity, $currentUser)
            : false;

        // Construction dynamique des étapes affichées dans l'historique du workflow.
        $workflowSteps = [];
        // Récupère tous les rôles/services qui doivent valider cette demande
        $requiredRoles = $workflowStateResolver->getParallelRequiredRoles($requestEntity);
        // Ajoute RH si la première étape est RH
        $status = $requestEntity->getStatus();
        if ($status === AccessRequest::STATUS_EN_ATTENTE_RH || $status === AccessRequest::STATUS_EN_ATTENTE_VALIDATION) {
            $workflowSteps[] = [
                'label' => 'RH',
                'role' => 'ROLE_RH',
                'stepDone' => $status !== AccessRequest::STATUS_EN_ATTENTE_RH
            ];
        }
        foreach ($requiredRoles as $role) {
            // Label lisible
            $label = match ($role) {
                'ROLE_ST' => 'ST',
                'ROLE_DSI' => 'DSI',
                default => $role,
            };
            $stepDone = $workflowStateResolver->hasRoleAlreadyValidated($requestEntity, $role);
            $workflowSteps[] = [
                'label' => $label,
                'role' => $role,
                'stepDone' => $stepDone
            ];
        }

        // --- AJOUT : Filtrage strict des commentaires privés uniquement pour la DSI ---
        $isDsi = $this->isGranted('ROLE_DSI') || $this->isGranted('ROLE_ADMIN');
        $privateCommentsDsi = $isDsi
            ? $privateCommentRepository->findBy(['request' => $requestEntity, 'targetService' => 'DSI'], ['createdAt' => 'DESC'])
            : [];

        // Toutes ces clés sont utilisées par templates/list_request/show.html.twig.
        return $this->render('list_request/show.html.twig', [
            'requestEntity' => $requestEntity,

            'workflowStateResolver' => $workflowStateResolver,

            'canValidate'   => $this->isGranted(RequestVoter::VALIDATE, $requestEntity),
            'canRefuse'     => $this->isGranted(RequestVoter::REFUSE, $requestEntity),
            'canUndo'       => $canUndo,
            'canUnblock'    => $canUnblock,
            'canEditRequestInfo' => $canEditRequestInfo,
            'isBlockedByMissingValidator' => $isBlockedByMissingValidator,
            'missingValidatorLabel' => $missingValidatorLabel,
            'selectedServiceId' => $requestEntity->getAgent()?->getService()?->getId(),
            'availableServices' => $canEditRequestInfo
                ? $serviceRepository->findBy([], ['name' => 'ASC'])
                : [],
            'allServices' => $serviceRepository->findBy([], ['name' => 'ASC']),
            'availableLogiciels' => $canEditRequestInfo
                ? $allLogiciels
                : [],
            'availableMateriels' => $canEditRequestInfo
                ? $allMateriels
                : [],
            'allLogiciels' => $allLogiciels,
            'workflowSteps' => $workflowSteps,
            'allMateriels' => $allMateriels,
            'canMarkReturned' => $canMarkReturned,
            'canFinalizeClosure' => $canFinalizeClosure,
            'closurePendingMateriels' => $closurePendingMateriels,
            'closureReturnedMateriels' => $closureReturnedMateriels,
            'closureUntrackedMateriels' => $closureUntrackedMateriels,
            'closureManageableMaterielIds' => $closureManageableMaterielIds,
            'closureOwnerById' => $closureOwnerById,
            'currentUserHasValidated' => $currentUserHasValidated,
            'isInfoEditLocked' => $isInfoEditLocked,

            // Variables pour les commentaires privés DSI
            'privateCommentsDsi' => $privateCommentsDsi,
            'isDsi' => $isDsi,
        ]);
    }

    private function resolveClosureOwnerRole(Ressource $ressource): string
    {
        // Attribution historique par nom de matériel. A remplacer par un champ métier sur Ressource.
        $name = mb_strtolower((string) ($ressource->getName() ?? ''));

        $isDsiMaterial = str_contains($name, 'ordinateur')
            || str_contains($name, 'telephone')
            || str_contains($name, 'téléphone');

        $isStMaterial = str_contains($name, 'cle')
            || str_contains($name, 'clé')
            || str_contains($name, 'badge')
            || str_contains($name, 'casque')
            || str_contains($name, 'gilet')
            || str_contains($name, 'chaussure')
            || str_contains($name, 'pantalon')
            || str_contains($name, 'veste')
            || str_contains($name, 'gant')
            || str_contains($name, 'lunette')
            || str_contains($name, 'harnais')
            || str_contains($name, 'masque')
            || str_contains($name, 'protection');

        if ($isDsiMaterial) {
            return 'ROLE_DSI';
        }

        if ($isStMaterial) {
            return 'ROLE_ST';
        }

        return 'ROLE_RH';
    }

    private function canViewClosureMaterial(Ressource $ressource, User $user): bool
    {
        // RH et admin peuvent tout consulter ; ST et DSI ne voient que leur périmètre matériel.
        $viewerRole = $this->getWorkflowRoleFromUserService($user);
        if ($viewerRole === null) {
            return $this->isGranted('ROLE_ADMIN');
        }

        if ($viewerRole === 'ROLE_RH') {
            return true;
        }

        return $this->resolveClosureOwnerRole($ressource) === $viewerRole;
    }

    // route pour valider une demande
    // ! route qui permet de valider une demande d'accès spécifique
    #[Route('/request/{id}/validate', name: 'app_request_validate', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function validate(AccessRequest $requestEntity, HttpRequest $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
        // Valide une étape habituelle ou finalise une fermeture lorsque tous les matériels sont remis.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Les fermetures n'utilisent pas le voter classique : elles sont finalisables par tout utilisateur autorisé.
        $canFinalizeClosure = $workflowService->canFinalizeClosureByAnyUser($requestEntity);

        if (!$this->isGranted(RequestVoter::VALIDATE, $requestEntity) && !$canFinalizeClosure) {
            // revalide après refresh pour éviter un faux 403 sur état intermédiaire.
            $entityManager->refresh($requestEntity);
            $canFinalizeClosure = $workflowService->canFinalizeClosureByAnyUser($requestEntity);
            if (!$this->isGranted(RequestVoter::VALIDATE, $requestEntity) && !$canFinalizeClosure) {
                $this->addRequestFlash($httpRequest, 'warning', 'Une action est déjà en cours. Recharger la page.');

                return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
            }
        }

        if (!$this->isCsrfTokenValid('workflow_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $submittedVersion = (int) $httpRequest->request->get('version', 0);
        if ($submittedVersion <= 0) {
            $this->addRequestFlash($httpRequest, 'danger', 'Version de la demande invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
        } catch (OptimisticLockException) {
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a été modifiée entre-temps. Recharger la page puis réessayer.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $comment = (string) $httpRequest->request->get('comment', '');
            $adminService = $httpRequest->request->get('admin_service');
            if ($this->isGranted(RequestVoter::VALIDATE, $requestEntity)) {
                $workflowService->validate($requestEntity, $user, $comment, $adminService);
            } else {
                $workflowService->finalizeClosureByAnyUser($requestEntity, $user, $comment);
            }
            $this->addRequestFlash($httpRequest, 'success', 'La demande a été validée.');
        } catch (\InvalidArgumentException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        } catch (\LogicException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    #[Route('/request/{id}/undo-decision', name: 'app_request_undo_decision', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function undoDecision(AccessRequest $requestEntity, HttpRequest $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
        // Annule la dernière décision globale du workflow quand le voter l'autorise.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isGranted(RequestVoter::UNDO, $requestEntity)) {
            $entityManager->refresh($requestEntity);
            if (!$this->isGranted(RequestVoter::UNDO, $requestEntity)) {
                $this->addRequestFlash($httpRequest, 'warning', 'Impossible d\'annuler cette décision. Recharger la page.');

                return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
            }
        }

        if (!$this->isCsrfTokenValid('workflow_undo_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $submittedVersion = (int) $httpRequest->request->get('version', 0);
        if ($submittedVersion <= 0) {
            $this->addRequestFlash($httpRequest, 'danger', 'Version de la demande invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
        } catch (OptimisticLockException) {
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a été modifiée entre-temps. Rechargez la page puis réessayez.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $workflowService->undoLastDecision($requestEntity, $user, (string) $httpRequest->request->get('comment', ''));
            $this->addRequestFlash($httpRequest, 'info', 'La dernière décision a été annulée.');
        } catch (\InvalidArgumentException | \LogicException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    #[Route('/request/{id}/undo-decision/{role}', name: 'app_request_undo_decision_role', methods: ['POST'], requirements: ['id' => '\d+', 'role' => '.+'])]
    public function undoDecisionForRole(
        AccessRequest $requestEntity,
        string $role,
        HttpRequest $httpRequest,
        WorkflowService $workflowService,
        EntityManagerInterface $entityManager
    ): Response {
        // Variante d'annulation limitée à un rôle de validation précis.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Permission spécifique undo par service
        if (!$workflowService->canUndoLastDecisionForRole($requestEntity, $user, $role)) {
            $entityManager->refresh($requestEntity);
            if (!$workflowService->canUndoLastDecisionForRole($requestEntity, $user, $role)) {
                $this->addRequestFlash($httpRequest, 'warning', 'Impossible d\'annuler cette décision pour ce service. Recharger la page.');
                return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
            }
        }

        if (!$this->isCsrfTokenValid('workflow_undo_' . $requestEntity->getId() . '_' . $role, (string) $httpRequest->request->get('_token'))) {
            $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $submittedVersion = (int) $httpRequest->request->get('version', 0);
        if ($submittedVersion <= 0) {
            $this->addRequestFlash($httpRequest, 'danger', 'Version de la demande invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
        } catch (OptimisticLockException) {
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a été modifiée entre-temps. Rechargez la page puis réessayez.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $workflowService->undoLastDecisionForRole(
                $requestEntity,
                $user,
                $role,
                (string) $httpRequest->request->get('comment', '')
            );
            $this->addRequestFlash($httpRequest, 'info', 'La dernière décision pour ce service a été annulée.');
        } catch (\InvalidArgumentException | \LogicException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    // route pour refuser une demande
    // ! route qui permet de refuser une demande d'accès spécifique
    #[Route('/request/{id}/refuse', name: 'app_request_refuse', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function refuse(AccessRequest $requestEntity, HttpRequest $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
        // Refuse la demande à l'étape attribuée à l'utilisateur connecté.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isGranted(RequestVoter::REFUSE, $requestEntity)) {
            // Revalide après refresh pour éviter un faux 403 sur état intermédiaire.
            $entityManager->refresh($requestEntity);
            if (!$this->isGranted(RequestVoter::REFUSE, $requestEntity)) {
                $this->addRequestFlash($httpRequest, 'warning', 'Une action est déjà en cours. Recharger la page.');

                return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
            }
        }

        if (!$this->isCsrfTokenValid('workflow_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $submittedVersion = (int) $httpRequest->request->get('version', 0);
        if ($submittedVersion <= 0) {
            $this->addRequestFlash($httpRequest, 'danger', 'Version de la demande invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
        } catch (OptimisticLockException) {
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a été modifiée entre-temps. Recharger la page puis réessayer.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $workflowService->refuse($requestEntity, $user, (string) $httpRequest->request->get('comment', ''));
            $this->addRequestFlash($httpRequest, 'warning', 'La demande a été refusée.');
        } catch (\InvalidArgumentException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        } catch (\LogicException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    // ! route pour modifier les informations d'une demande (après refus RH ou pour corriger une erreur)
    // ! elle permet de mettre à jour les informations d'une demande d'accès spécifique
    #[Route('/request/{id}/update-info', name: 'app_request_update_info', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function updateInfo(
        AccessRequest $requestEntity,
        HttpRequest $httpRequest,
        EntityManagerInterface $entityManager,
        RequestUpdateInfoService $requestUpdateInfoService,
        WorkflowService $workflowService,
        MessageBusInterface $messageBus
    ): Response {
        // Met à jour les informations saisies après un refus ou durant une phase autorisée du workflow.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $status = (string) ($requestEntity->getStatus() ?? '');
        if ($status === AccessRequest::STATUS_TRAITEE) {
            $this->addRequestFlash($httpRequest, 'warning', 'La demande est validée. Modification impossible.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        if (!$this->isGranted(RequestVoter::EDIT_INFO, $requestEntity)) {
            // Revalide après refresh pour éviter un faux 403 sur état intermédiaire.
            $entityManager->refresh($requestEntity);
            if (!$this->isGranted(RequestVoter::EDIT_INFO, $requestEntity)) {
                if ($workflowService->isInfoEditLocked($requestEntity, $user)) {
                    $this->addRequestFlash($httpRequest, 'warning', 'Vous avez déjà effectué une modification dans ce cycle. Annulez la dernière décision pour modifier de nouveau.');

                    return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
                }

                $this->addRequestFlash($httpRequest, 'warning', 'Une action est déjà en cours. Recharger la page.');

                return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
            }
        }


        // ! vérification du token CSRF pour sécuriser la requête de mise à jour des informations de la demande
        if (!$this->isCsrfTokenValid('request_edit_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $submittedVersion = (int) $httpRequest->request->get('version', 0);
        if ($submittedVersion <= 0) {
            $this->addRequestFlash($httpRequest, 'danger', 'Version de la demande invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        // Le service valide et applique les champs ; le contrôleur ne fait que lire la requête HTTP.
        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
        } catch (OptimisticLockException) {
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a été modifiée entre-temps. Rechargez la page puis réessayez.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            /** @var array<string> $logiciels */
            $logiciels = $httpRequest->request->all('logiciels');
            /** @var array<string> $materiels */
            $materiels = $httpRequest->request->all('materiel');

            $requestUpdateInfoService->update($requestEntity, [
                'type' => (string) $httpRequest->request->get('type', $requestEntity->getType() ?? AccessRequest::TYPE_OUVERTURE),
                'civilite' => (string) $httpRequest->request->get('civilite', ''),
                'prenom' => (string) $httpRequest->request->get('prenom', ''),
                'nom' => (string) $httpRequest->request->get('nom', ''),
                'fonction' => (string) $httpRequest->request->get('fonction', ''),
                'email' => (string) $httpRequest->request->get('email', ''),
                'replacee_par' => (string) $httpRequest->request->get('replacee_par', ''),
                'service' => (int) $httpRequest->request->get('service', 0),
                'taille_vetements' => (string) $httpRequest->request->get('taille_vetements', ''),
                'taille_chaussures' => (string) $httpRequest->request->get('taille_chaussures', ''),
                'date_arrivee' => (string) $httpRequest->request->get('date_arrivee', ''),
                'date_depart' => (string) $httpRequest->request->get('date_depart', ''),
                'commentaire' => (string) $httpRequest->request->get('commentaire', ''),
                'logiciels' => $logiciels,
                'materiel' => $materiels,
            ], $user);

            $messageBus->dispatch(new WorkflowNotificationMessage(
                (int) $requestEntity->getId(),
                trim((string) $httpRequest->request->get('commentaire', '')) !== ''
                    ? (string) $httpRequest->request->get('commentaire', '')
                    : 'Informations de la demande mises à jour.'
            ));

            $this->addRequestFlash($httpRequest, 'success', 'Les informations de la demande ont été mises à jour.');
        } catch (\InvalidArgumentException | \LogicException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    private function addRequestFlash(HttpRequest $httpRequest, string $type, string $message): void
    {
        // Certaines requêtes de test ou AJAX n'ont pas de session : dans ce cas aucun flash n'est ajouté.
        if (!$httpRequest->hasSession()) {
            return;
        }

        $session = $httpRequest->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add($type, $message);
    }

    private function canManageClosureMaterial(Ressource $ressource, User $user): bool
    {
        // Même règle que la visibilité, mais utilisée pour décider si le bouton peut modifier le statut.
        $viewerRole = $this->getWorkflowRoleFromUserService($user);
        if ($viewerRole === null) {
            return $this->isGranted('ROLE_ADMIN');
        }

        if ($viewerRole === 'ROLE_RH') {
            return true;
        }

        return $this->resolveClosureOwnerRole($ressource) === $viewerRole;
    }

    private function getWorkflowRoleFromUserService(User $user): ?string
    {
        // Les rôles de workflow sont dérivés du code du service, par exemple "DSI" devient "ROLE_DSI".
        $serviceCode = strtoupper(trim((string) ($user->getService()?->getCode() ?? '')));
        if ($serviceCode === '') {
            return null;
        }

        return 'ROLE_' . $serviceCode;
    }

    #[Route('/request/{id}/unblock', name: 'app_request_unblock', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function unblock(AccessRequest $requestEntity, HttpRequest $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
        // RH relance une demande bloquée lorsqu'un validateur est de nouveau disponible.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isGranted(RequestVoter::UNBLOCK, $requestEntity)) {
            $entityManager->refresh($requestEntity);
            if (!$this->isGranted(RequestVoter::UNBLOCK, $requestEntity)) {
                $this->addRequestFlash($httpRequest, 'warning', 'Déblocage impossible. La demande n\'est pas bloquée.');
                return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
            }
        }

        if (!$this->isCsrfTokenValid('workflow_unblock_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addRequestFlash($httpRequest, 'danger', 'Token de securite invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $submittedVersion = (int) $httpRequest->request->get('version', 0);
        if ($submittedVersion <= 0) {
            $this->addRequestFlash($httpRequest, 'danger', 'Version de la demande invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
            $workflowService->unblockByRh($requestEntity, $user, (string) $httpRequest->request->get('comment', ''));
            $this->addRequestFlash($httpRequest, 'info', 'La demande a ete debloquée par RH.');
        } catch (OptimisticLockException) {
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a ete modifiée entre-temps. Rechargez la page puis reessayez.');
        } catch (\InvalidArgumentException | \LogicException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    #[Route('/my-requests', name: 'app_my_requests', methods: ['GET'])]
    public function myRequests(RequestRepository $requestRepository, HttpRequest $request, ServiceRepository $serviceRepository): Response
    {
        // Liste paginée des demandes créées par l'utilisateur connecté.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        // Configuration de la pagination
        $page = max(1, $request->query->getInt('page', 1));
        $limit = 10;

        $filters = [
            'serviceId' => $request->query->getInt('serviceId') ?: null,
            'type' => $request->query->get('type') ?: null,
            'agent' => trim((string) $request->query->get('agent', '')),
        ];

        $services = $serviceRepository->findBy([], ['name' => 'DESC']);

        // Récupère uniquement les demandes de la page en cours selon les filtres.
        $paginator = $requestRepository->findPaginatedRequestsByAuthor($currentUser, $page, $limit, $filters);

        // Le count du paginator déclenche une requête dédiée, gardée légère côté repository.
        $totalRequests = count($paginator);
        $pagesCount = max(1, (int) ceil($totalRequests / $limit));

        return $this->render('list_request/my_requests.html.twig', [
            'requests'    => $paginator, // On passe l'objet paginé (qui s'utilise comme un tableau en Twig)
            'currentPage' => $page,
            'pagesCount'  => $pagesCount,
            'totalRequests' => $totalRequests,
            'services' => $services,
            'filters' => $filters,
        ]);
    }

}
