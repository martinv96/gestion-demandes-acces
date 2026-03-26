<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Repository\RessourceRepository;
use App\Repository\RequestRepository;
use App\Repository\ServiceRepository;
use App\Service\WorkflowService;
use App\Security\Voter\RequestVoter;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;


final class ListRequestController extends AbstractController
{
    // route pour afficher la liste des demandes
    #[Route('/list/request', name: 'app_list_request', methods: ['GET'])]
    public function index(RequestRepository $requestRepository): Response
    {
        $requests = $requestRepository->findLatestWithRelations();

        return $this->render('list_request/index.html.twig', [
            'requests' => $requests,
        ]);
    }

    // route pour afficher les détails d'une demande
    #[Route('/request/{id}', name: 'app_request_show', methods: ['GET'], requirements: ['id' => '\\d+'])]
    public function show(
        AccessRequest $requestEntity,
        WorkflowService $workflowService,
        RequestRepository $requestRepository,
        RessourceRepository $ressourceRepository,
        ServiceRepository $serviceRepository
    ): Response {
        $user = $this->getUser();
        $displayNumber = $requestRepository->getDisplayNumber($requestEntity);
        $canEditRequestInfo = $this->isGranted(RequestVoter::EDIT_INFO, $requestEntity);

        return $this->render('list_request/show.html.twig', [
            'requestEntity' => $requestEntity,
            'requestDisplayCode' => sprintf('REQ-%03d', $displayNumber),
            'canValidate'   => $this->isGranted(RequestVoter::VALIDATE, $requestEntity),
            'canRefuse'     => $this->isGranted(RequestVoter::REFUSE, $requestEntity),
            'canEditRequestInfo' => $canEditRequestInfo,
            'selectedServiceId' => $requestEntity->getAgent()?->getService()?->getId(),
            'availableServices' => $canEditRequestInfo
                ? $serviceRepository->findBy([], ['name' => 'ASC'])
                : [],
            'availableLogiciels' => $canEditRequestInfo
                ? $ressourceRepository->findBy(['category' => 'logiciel', 'isActive' => true], ['name' => 'ASC'])
                : [],
            'availableMateriels' => $canEditRequestInfo
                ? $ressourceRepository->findBy(['category' => 'materiel', 'isActive' => true], ['name' => 'ASC'])
                : [],
        ]);
    }

    // route pour valider une demande
    #[Route('/request/{id}/validate', name: 'app_request_validate', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function validate(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(RequestVoter::VALIDATE, $requestEntity);

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

    // route pour refuser une demande
    #[Route('/request/{id}/refuse', name: 'app_request_refuse', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function refuse(AccessRequest $requestEntity, Request $httpRequest, WorkflowService $workflowService): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(RequestVoter::REFUSE, $requestEntity);

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

    // route pour modifier les informations d'une demande (après refus RH ou pour corriger une erreur)
    #[Route('/request/{id}/update-info', name: 'app_request_update_info', methods: ['POST'], requirements: ['id' => '\\d+'])]
    public function updateInfo(
        AccessRequest $requestEntity,
        Request $httpRequest,
        EntityManagerInterface $entityManager
    ): Response {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        $this->denyAccessUnlessGranted(RequestVoter::EDIT_INFO, $requestEntity);

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

            $serviceId = (int) $httpRequest->request->get('service', 0);
            if ($serviceId > 0) {
                $service = $entityManager->getRepository(Service::class)->find($serviceId);
                if (!$service instanceof Service) {
                    throw new \InvalidArgumentException('Service invalide.');
                }
                $agent->setService($service);
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

            // Si la demande était refusée par RH, la repasser à "en_attente_rh" après modification
            if ($requestEntity->getStatus() === 'refusee_rh') {
                $requestEntity->setStatus('en_attente_rh');
            }

            $requestEntity->setUpdateDate(new \DateTimeImmutable());

            $entityManager->flush();
            $this->addFlash('success', 'Les informations de la demande ont été mises à jour.');
        } catch (\InvalidArgumentException | \LogicException $e) {
            $this->addFlash('danger', $e->getMessage());
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    // Méthode utilitaire pour trouver ou créer une ressource
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
    // Méthode utilitaire pour normaliser les noms de ressources (trim, unique, etc.)
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

}
