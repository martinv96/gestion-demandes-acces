<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\User;
use App\Repository\RessourceRepository;
use App\Repository\RequestRepository;
use App\Repository\ServiceRepository;
use App\Service\RequestExportSpreadsheetService;
use App\Service\RequestUpdateInfoService;
use App\Service\WorkflowService;
use App\Security\Voter\RequestVoter;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;




final class ListRequestController extends AbstractController
{
    // route pour afficher la liste des demandes
    // ! route qui affiche la liste de toutes les demandes d'accès (avec status type et date)
    // ! + un lien vers la page de détail de chaque demande
    #[Route('/list/request', name: 'app_list_request', methods: ['GET'])]
    public function index(RequestRepository $requestRepository, ServiceRepository $serviceRepository, Request $httpRequest): Response
    {


        $limit = 10;
        $page = $httpRequest->query->getInt('page', 1);
        if ($page < 1) $page = 1;
        $offset = ($page - 1) * $limit;



        // ! gestion des filtres de recherche : status, service, type et date d'arrivée
        $allowedStatuses = AccessRequest::WORKFLOW_STATUSES;
        $allowedTypes = AccessRequest::TYPES;

        $status        = (string) $httpRequest->query->get('status', '');
        $serviceId     = (string) $httpRequest->query->get('serviceId', '');
        $type          = (string) $httpRequest->query->get('type', '');
        $arrivalDate   = (string) $httpRequest->query->get('arrivalDate', '');
        $departureDate = (string) $httpRequest->query->get('departureDate', '');
        $agent         = trim((string) $httpRequest->query->get('agent', ''));

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

        $requests            = $requestRepository->findWithFilters($filters, $limit, $offset);
        $services            = $serviceRepository->findBy([], ['name' => 'DESC']);
        $availableDates      = $requestRepository->findDistinctCurrentArrivalDates();
        $availableDepartures = $requestRepository->findDistinctCurrentDepartureDates();
        $totalCount          = $requestRepository->countCurrent();
        $totalWithFilters    = $requestRepository->countWithFilters($filters);
        $maxPages            = ceil($totalWithFilters / $limit);


        return $this->render('list_request/index.html.twig', [
            'requests'            => $requests,
            'services'            => $services,
            'availableDates'      => $availableDates,
            'availableDepartures' => $availableDepartures,
            'currentPage'         => $page,
            'maxPages'            => $maxPages,
            'totalCount'          => $totalCount,
            'filters'             => [
                'status'        => $status,
                'serviceId'     => $serviceId,
                'type'          => $type,
                'arrivalDate'   => $arrivalDate,
                'departureDate' => $departureDate,
                'agent'         => $agent,
            ],
            'exportScope' => 'current',
        ]);
    }

    // route pour afficher les détails d'une demande
    // ! route qui affiche les détails d'une demande d'accès spécifique
    #[Route('/request/{id}', name: 'app_request_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(
        AccessRequest $requestEntity,
        WorkflowService $workflowService,
        RessourceRepository $ressourceRepository,
        ServiceRepository $serviceRepository
    ): Response {
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

        $currentUser = $this->getUser();

        if ($requestEntity->getType() === AccessRequest::TYPE_FERMETURE && $requestEntity->getParentRequest() instanceof AccessRequest) {
            $parentMateriels = array_values(array_filter(
                $requestEntity->getParentRequest()->getRessources()->toArray(),
                static fn($r) => $r instanceof Ressource && $r->getCategory() === 'materiel'
            ));

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

                $closureTrackedMateriels[] = $materiel;
                if ($materiel->getId() !== null && in_array($materiel->getId(), $pendingIds, true)) {
                    $closurePendingMateriels[] = $materiel;
                } else {
                    $closureReturnedMateriels[] = $materiel;
                }
            }

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

        $canMarkReturned = $requestEntity->getType() === AccessRequest::TYPE_FERMETURE
            && $requestEntity->getStatus() !== AccessRequest::STATUS_TRAITEE;
        $canFinalizeClosure = $workflowService->canFinalizeClosureByAnyUser($requestEntity);

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

        return $this->render('list_request/show.html.twig', [
            'requestEntity' => $requestEntity,

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
            'availableLogiciels' => $canEditRequestInfo
                ? $allLogiciels
                : [],
            'availableMateriels' => $canEditRequestInfo
                ? $allMateriels
                : [],
            'allLogiciels' => $allLogiciels,
            'allMateriels' => $allMateriels,
            'canMarkReturned' => $canMarkReturned,
            'canFinalizeClosure' => $canFinalizeClosure,
            'closurePendingMateriels' => $closurePendingMateriels,
            'closureReturnedMateriels' => $closureReturnedMateriels,
            'closureUntrackedMateriels' => $closureUntrackedMateriels,
            'closureManageableMaterielIds' => $closureManageableMaterielIds,
            'closureOwnerById' => $closureOwnerById,
        ]);
    }

    private function resolveClosureOwnerRole(Ressource $ressource): string
    {
        $name = mb_strtolower((string) ($ressource->getName() ?? ''));

        $isDsiMaterial = str_contains($name, 'ordinateur')
            || str_contains($name, 'telephone')
            || str_contains($name, 'téléphone');

        $isStMaterial = str_contains($name, 'cle')
            || str_contains($name, 'clé')
            || str_contains($name, 'badge');

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
    public function validate(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $canFinalizeClosure = $workflowService->canFinalizeClosureByAnyUser($requestEntity);

        if (!$this->isGranted(RequestVoter::VALIDATE, $requestEntity) && !$canFinalizeClosure) {
            // Revalide après refresh pour éviter un faux 403 sur état intermédiaire.
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
            if ($this->isGranted(RequestVoter::VALIDATE, $requestEntity)) {
                $workflowService->validate($requestEntity, $user, $comment);
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
    public function undoDecision(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
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
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a été modifiée entre-temps. Recharger la page puis réessayer.');

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

    // route pour refuser une demande
    // ! route qui permet de refuser une demande d'accès spécifique
    #[Route('/request/{id}/refuse', name: 'app_request_refuse', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function refuse(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
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
        Request $httpRequest,
        EntityManagerInterface $entityManager,
        RequestUpdateInfoService $requestUpdateInfoService,
        WorkflowService $workflowService,
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isGranted(RequestVoter::EDIT_INFO, $requestEntity)) {
            // Revalide après refresh pour éviter un faux 403 sur état intermédiaire.
            $entityManager->refresh($requestEntity);
            if (!$this->isGranted(RequestVoter::EDIT_INFO, $requestEntity)) {
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
                'service' => (int) $httpRequest->request->get('service', 0),
                'date_arrivee' => (string) $httpRequest->request->get('date_arrivee', ''),
                'date_depart' => (string) $httpRequest->request->get('date_depart', ''),
                'commentaire' => (string) $httpRequest->request->get('commentaire', ''),
                'logiciels' => $logiciels,
                'materiel' => $materiels,
            ]);

            $workflowService->notifyAllActors(
                $requestEntity,
                trim((string) $httpRequest->request->get('commentaire', '')) !== ''
                    ? (string) $httpRequest->request->get('commentaire', '')
                    : 'Informations de la demande mises à jour.'
            );

            $this->addRequestFlash($httpRequest, 'success', 'Les informations de la demande ont été mises à jour.');
        } catch (\InvalidArgumentException | \LogicException $e) {
            $this->addRequestFlash($httpRequest, 'danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    private function addRequestFlash(Request $httpRequest, string $type, string $message): void
    {
        if (!$httpRequest->hasSession()) {
            return;
        }

        $session = $httpRequest->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add($type, $message);
    }

    #[Route('/request/{id}/mark-returned/{ressourceId}', name: 'app_request_mark_returned', methods: ['POST'], requirements: ['id' => '\\d+', 'ressourceId' => '\\d+'])]
    public function markReturned(
        AccessRequest $requestEntity,
        int $ressourceId,
        Request $httpRequest,
        EntityManagerInterface $entityManager,
        WorkflowService $workflowService
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $isAjax = $httpRequest->isXmlHttpRequest();

        if ($requestEntity->getType() !== AccessRequest::TYPE_FERMETURE) {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => 'Action disponible uniquement pour une demande de fermeture.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addRequestFlash($httpRequest, 'warning', 'Action disponible uniquement pour une demande de fermeture.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        if ($requestEntity->getStatus() === AccessRequest::STATUS_TRAITEE) {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => 'Cette demande est déjà traitée.'], Response::HTTP_CONFLICT);
            }
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande est déjà traitée.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        if (!$this->isCsrfTokenValid('mark_returned_' . $requestEntity->getId() . '_' . $ressourceId, (string) $httpRequest->request->get('_token'))) {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => 'Token de sécurité invalide.'], Response::HTTP_FORBIDDEN);
            }
            $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $submittedVersion = (int) $httpRequest->request->get('version', 0);
        if ($submittedVersion <= 0) {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => 'Version de la demande invalide.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addRequestFlash($httpRequest, 'danger', 'Version de la demande invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
        } catch (OptimisticLockException) {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => 'Cette demande a été modifiée entre-temps. Rechargez la page puis réessayez.'], Response::HTTP_CONFLICT);
            }
            $this->addRequestFlash($httpRequest, 'warning', 'Cette demande a été modifiée entre-temps. Rechargez la page puis réessayez.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        /** @var Ressource|null $ressource */
        $ressource = $entityManager->getRepository(Ressource::class)->find($ressourceId);
        if (!$ressource instanceof Ressource || $ressource->getCategory() !== 'materiel') {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => 'Matériel introuvable.'], Response::HTTP_NOT_FOUND);
            }
            $this->addRequestFlash($httpRequest, 'danger', 'Matériel introuvable.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        if (!$this->canManageClosureMaterial($ressource, $user)) {
            $message = 'Ce matériel ne relève pas de votre service.';
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => $message], Response::HTTP_FORBIDDEN);
            }

            $this->addRequestFlash($httpRequest, 'danger', $message);

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $parentRequest = $requestEntity->getParentRequest();
        $isTrackedByClosure = $parentRequest instanceof AccessRequest
            && $parentRequest->getRessources()->contains($ressource);

        if (!$isTrackedByClosure) {
            if ($isAjax) {
                return new JsonResponse(['ok' => false, 'message' => 'Ce matériel n\'est pas lié à la demande d\'origine.'], Response::HTTP_BAD_REQUEST);
            }
            $this->addRequestFlash($httpRequest, 'danger', 'Ce matériel n\'est pas lié à la demande d\'origine.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        if ($requestEntity->getRessources()->contains($ressource)) {
            $requestEntity->removeRessource($ressource);
            $message = 'Matériel marqué comme remis.';
            $newStatus = 'remis';
        } else {
            $requestEntity->addRessource($ressource);
            $message = 'Matériel repassé en non remis.';
            $newStatus = 'non_remis';
        }

        $requestEntity->setUpdateDate(new \DateTimeImmutable());

        $entityManager->flush();

        $workflowService->notifyAllActors($requestEntity, $message);

        if ($isAjax) {
            return new JsonResponse([
                'ok' => true,
                'message' => $message,
                'ressourceId' => $ressourceId,
                'newStatus' => $newStatus,
                'version' => $requestEntity->getVersion(),
                'canFinalizeClosure' => $workflowService->canFinalizeClosureByAnyUser($requestEntity),
            ]);
        }

        $this->addRequestFlash($httpRequest, 'success', $message);

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    private function canManageClosureMaterial(Ressource $ressource, User $user): bool
    {
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
        $serviceCode = strtoupper(trim((string) ($user->getService()?->getCode() ?? '')));
        if ($serviceCode === '') {
            return null;
        }

        return 'ROLE_' . $serviceCode;
    }

    // ! route pour exporter la liste des demandes au format CSV
    #[Route('/request/exportCsv', name: 'app_request_export_csv', methods: ['GET'])]
    public function exportXlsx(
        Request $httpRequest,
        RequestExportSpreadsheetService $requestExportSpreadsheetService
    ): Response {
        // ! vérification que l'utilisateur est authentifié avant de permettre l'exportation de la liste des demandes
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        // Reprend la logique de filtres de ta liste
        $allowedStatuses = AccessRequest::WORKFLOW_STATUSES;
        $allowedTypes = AccessRequest::TYPES;

        $status = (string) $httpRequest->query->get('status', '');
        $serviceId = (string) $httpRequest->query->get('serviceId', '');
        $type = (string) $httpRequest->query->get('type', '');
        $arrivalDate = (string) $httpRequest->query->get('arrivalDate', '');
        $departureDate = (string) $httpRequest->query->get('departureDate', '');
        $agent = trim((string) $httpRequest->query->get('agent', ''));

        // partie filtrage des demandes en fonction des critères de recherche fournis dans la requête HTTP
        $filters = [];

        // ! pour chaque critère de filtre (status, serviceId, type, arrivalDate, departureDate, agent), 
        // ! on vérifie s'il est présent et valide dans la requête, 
        // ! puis on l'ajoute au tableau de filtres qui sera utilisé pour interroger la base de données
        if ($status !== '' && in_array($status, $allowedStatuses, true)) {
            $filters['status'] = $status;
        }

        if ($serviceId !== '' && ctype_digit($serviceId)) {
            $filters['serviceId'] = (int) $serviceId;
        }

        if ($type !== '' && in_array($type, $allowedTypes, true)) {
            $filters['type'] = $type;
        }

        if ($arrivalDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $arrivalDate)) {
            $filters['arrivalDate'] = $arrivalDate;
        }

        if ($departureDate !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $departureDate)) {
            $filters['departureDate'] = $departureDate;
        }

        if ($agent !== '' && mb_strlen($agent) <= 100) {
            $filters['agent'] = $agent;
        }

        $scope = (string) $httpRequest->query->get('scope', 'current');

        $spreadsheet = $requestExportSpreadsheetService->buildSpreadsheet($filters, $scope);

        $filename = sprintf('demandes_acces_%s.xlsx', (new \DateTimeImmutable())->format('Y-m-d_H\hi'));

        $response = new StreamedResponse(function () use ($spreadsheet): void {
            $writer = new Xlsx($spreadsheet);
            $writer->setPreCalculateFormulas(false);
            $writer->save('php://output');

            $spreadsheet->disconnectWorksheets();
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    #[Route('/request/{id}/unblock', name: 'app_request_unblock', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function unblock(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService, EntityManagerInterface $entityManager): Response
    {
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
}
