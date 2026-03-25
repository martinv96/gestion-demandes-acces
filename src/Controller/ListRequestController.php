<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Repository\RessourceRepository;
use App\Repository\RequestRepository;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class ListRequestController extends AbstractController
{
    #[Route('/list/request', name: 'app_list_request', methods: ['GET'])]
    public function index(RequestRepository $requestRepository): Response
    {
        $requests = $requestRepository->findLatestWithRelations();

        return $this->render('list_request/index.html.twig', [
            'requests' => $requests,
        ]);
    }

    #[Route('/request/{id}', name: 'app_request_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(
        AccessRequest $requestEntity,
        WorkflowService $workflowService,
        RequestRepository $requestRepository,
        RessourceRepository $ressourceRepository
    ): Response
    {
        $user = $this->getUser();
        $displayNumber = $requestRepository->getDisplayNumber($requestEntity);
        $canEditRequestInfo = $user instanceof User
            && in_array('ROLE_RH', $user->getRoles(), true)
            && $this->canEditAfterRefusal($requestEntity);

        return $this->render('list_request/show.html.twig', [
            'requestEntity' => $requestEntity,
            'requestDisplayCode' => sprintf('REQ-%03d', $displayNumber),
            'canValidate'   => $user instanceof User && $workflowService->canValidate($requestEntity, $user),
            'canRefuse'     => $user instanceof User && $workflowService->canRefuse($requestEntity, $user),
            'canEditRequestInfo' => $canEditRequestInfo,
            'currentServiceCode' => $this->resolveServiceCode($requestEntity->getAgent()?->getService()),
            'availableLogiciels' => $canEditRequestInfo
                ? $ressourceRepository->findBy(['category' => 'logiciel', 'isActive' => true], ['name' => 'ASC'])
                : [],
        ]);
    }

    #[Route('/request/{id}/validate', name: 'app_request_validate', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function validate(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('workflow_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $workflowService->validate($requestEntity, $user, (string) $httpRequest->request->get('comment', ''));
            $this->addFlash('success', 'La demande a été validée.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    #[Route('/request/{id}/refuse', name: 'app_request_refuse', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function refuse(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!$this->isCsrfTokenValid('workflow_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $workflowService->refuse($requestEntity, $user, (string) $httpRequest->request->get('comment', ''));
            $this->addFlash('warning', 'La demande a été refusée.');
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
        } catch (\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    #[Route('/request/{id}/update-info', name: 'app_request_update_info', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function updateInfo(
        AccessRequest $requestEntity,
        Request $httpRequest,
        EntityManagerInterface $entityManager
    ): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        if (!in_array('ROLE_RH', $user->getRoles(), true)) {
            throw $this->createAccessDeniedException('Seul le RH peut modifier les informations de la demande.');
        }

        if (!$this->canEditAfterRefusal($requestEntity)) {
            throw $this->createAccessDeniedException('La modification RH est disponible uniquement après un refus.');
        }

        if (!$this->isCsrfTokenValid('request_edit_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');

            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        try {
            $type = (string) $httpRequest->request->get('type', $requestEntity->getType() ?? 'ouverture');
            if (!in_array($type, ['ouverture', 'modification', 'fermeture'], true)) {
                throw new \InvalidArgumentException('Type de demande invalide.');
            }

            $requestEntity->setType($type);

            $agent = $requestEntity->getAgent();
            if ($agent === null) {
                throw new \LogicException('Aucun agent associé à la demande.');
            }

            $agent
                ->setCivility((string) $httpRequest->request->get('civilite', $agent->getCivility() ?? 'N/A'))
                ->setFirstname((string) $httpRequest->request->get('prenom', $agent->getFirstname() ?? ''))
                ->setLastname((string) $httpRequest->request->get('nom', $agent->getLastname() ?? ''))
                ->setJobTitle((string) $httpRequest->request->get('fonction', $agent->getJobTitle() ?? ''));

            $serviceCode = (string) $httpRequest->request->get('service', '');
            if ($serviceCode !== '') {
                $agent->setService($this->findOrCreateService($serviceCode, $entityManager));
            }

            $arrivalDate = (string) $httpRequest->request->get('date_arrivee', '');
            if ($arrivalDate !== '') {
                $requestEntity->setArrivalDate(new \DateTime($arrivalDate));
            }

            $departureDate = (string) $httpRequest->request->get('date_depart', '');
            $requestEntity->setDepartureDate($departureDate !== '' ? new \DateTime($departureDate) : null);

            $requestEntity->setCommentary((string) $httpRequest->request->get('commentaire', ''));

            foreach ($requestEntity->getRessources()->toArray() as $existingResource) {
                $requestEntity->removeRessource($existingResource);
            }

            if ($type !== 'fermeture') {
                /** @var array<string> $logiciels */
                $logiciels = $httpRequest->request->all('logiciels');
                foreach ($this->normalizeResourceNames($logiciels) as $logicielName) {
                    $ressource = $this->findOrCreateRessource($logicielName, 'logiciel', $entityManager);
                    $ressource->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $requestEntity->addRessource($ressource);
                }

                /** @var array<string> $materiels */
                $materiels = $httpRequest->request->all('materiel');
                foreach ($this->normalizeResourceNames($materiels) as $materielName) {
                    $ressource = $this->findOrCreateRessource($materielName, 'materiel', $entityManager);
                    $ressource->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $requestEntity->addRessource($ressource);
                }
            }

            $requestEntity->setUpdateDate(new \DateTimeImmutable());

            $entityManager->flush();
            $this->addFlash('success', 'Les informations de la demande ont été mises à jour.');
        } catch (\InvalidArgumentException|\LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    private function findOrCreateRessource(
        string $name,
        string $category,
        EntityManagerInterface $entityManager
    ): Ressource {
        /** @var Ressource|null $ressource */
        $ressource = $entityManager->getRepository(Ressource::class)->findOneBy(['name' => $name]);
        if ($ressource instanceof Ressource) {
            return $ressource;
        }

        $ressource = new Ressource();
        $ressource
            ->setName($name)
            ->setCategory($category)
            ->setAssignmentStatus(Ressource::ASSIGNMENT_NON_ATTRIBUE)
            ->setIsActive(true);

        $entityManager->persist($ressource);

        return $ressource;
    }

    /**
     * @param array<string> $rawNames
     *
     * @return array<string>
     */
    private function normalizeResourceNames(array $rawNames): array
    {
        $normalized = [];

        foreach ($rawNames as $rawName) {
            if (!is_string($rawName)) {
                continue;
            }

            $name = trim($rawName);
            if ($name === '') {
                continue;
            }

            $normalized[] = $name;
        }

        return array_values(array_unique($normalized));
    }

    private function findOrCreateService(string $serviceCode, EntityManagerInterface $entityManager): Service
    {
        $serviceCode = strtolower(trim($serviceCode));
        $serviceName = $this->mapServiceCodeToLabel($serviceCode);

        /** @var Service|null $service */
        $service = $entityManager->getRepository(Service::class)->findOneBy(['name' => $serviceName]);
        if ($service instanceof Service) {
            if ($service->getCode() === null || $service->getCode() === '') {
                $service->setCode(strtoupper($serviceCode));
            }

            return $service;
        }

        $service = new Service();
        $service
            ->setName($serviceName)
            ->setEmail(sprintf('%s@mairie.local', $serviceCode))
            ->setCode(strtoupper($serviceCode));

        $entityManager->persist($service);

        return $service;
    }

    private function mapServiceCodeToLabel(string $serviceCode): string
    {
        return match ($serviceCode) {
            'urbanisme' => 'Service Urbanisme',
            'finances' => 'Service Finances',
            'etat-civil' => 'Service Etat Civil',
            'technique' => 'Service Technique',
            'rh' => 'Ressources Humaines',
            'dsi' => 'DSI',
            'dg' => 'Direction Generale',
            default => ucfirst($serviceCode),
        };
    }

    private function resolveServiceCode(?Service $service): ?string
    {
        if (!$service instanceof Service) {
            return null;
        }

        $code = $service->getCode();
        if (is_string($code) && trim($code) !== '') {
            return strtolower(trim($code));
        }

        return match ($service->getName()) {
            'Service Urbanisme' => 'urbanisme',
            'Service Finances' => 'finances',
            'Service Etat Civil' => 'etat-civil',
            'Service Technique' => 'technique',
            'Ressources Humaines' => 'rh',
            'DSI' => 'dsi',
            'Direction Generale' => 'dg',
            default => null,
        };
    }

    private function canEditAfterRefusal(AccessRequest $requestEntity): bool
    {
        $status = $requestEntity->getStatus() ?? '';

        if (in_array($status, [
            WorkflowService::STATUS_TRAITEE,
            WorkflowService::STATUS_REFUSEE_RH,
            WorkflowService::STATUS_REFUSEE_ST,
            WorkflowService::STATUS_REFUSEE_DSI,
        ], true)) {
            return false;
        }

        foreach ($requestEntity->getRequestId() as $historyEntry) {
            $oldStatus = $historyEntry->getOldStatus();
            $newStatus = $historyEntry->getNewStatus();

            $isRefusalFromSt = $oldStatus === WorkflowService::STATUS_EN_ATTENTE_ST
                && $newStatus === WorkflowService::STATUS_EN_ATTENTE_RH;
            $isRefusalFromDsi = $oldStatus === WorkflowService::STATUS_EN_ATTENTE_DSI
                && $newStatus === WorkflowService::STATUS_EN_ATTENTE_ST;

            if ($isRefusalFromSt || $isRefusalFromDsi) {
                return true;
            }
        }

        return false;
    }
}
