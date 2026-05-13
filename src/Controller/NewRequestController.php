<?php

namespace App\Controller;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Form\Model\NewRequestData;
use App\Form\NewRequestType;
use App\Message\WorkflowNotificationMessage;
use App\Repository\RequestRepository;
use App\Service\RequestCreationService;
use App\Service\WorkflowService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

final class NewRequestController extends AbstractController
{
    // route pour créer une nouvelle demande d'accès
    #[Route('/new/request', name: 'app_new_request', methods: ['GET', 'POST'])]
    public function index(Request $request, RequestRepository $requestRepository, RequestCreationService $requestCreationService, WorkflowService $workflowService, MessageBusInterface $messageBus): Response
    {
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $currentUser = $this->getUser();
        $isServiceTechniqueUser = $currentUser instanceof User
            && $currentUser->getService()?->getId() === 3;

        $canCreateAllRequestTypes = $this->isGranted('ROLE_ADMIN') || $this->isGranted('ROLE_RH');
        $allowedRequestTypes = $canCreateAllRequestTypes
            ? AccessRequest::TYPES
            : [AccessRequest::TYPE_OUVERTURE, AccessRequest::TYPE_FERMETURE];

        $formData = new NewRequestData();
        $form = $this->createForm(NewRequestType::class, $formData, [
            'allowed_types' => $allowedRequestTypes,
        ]);

        $form->handleRequest($request);

        // Si le formulaire est soumis et valide, créer la demande d'accès
        if ($form->isSubmitted() && $form->isValid()) {
            $requestType = $formData->getType() ?? AccessRequest::TYPE_OUVERTURE;

            // si l'user est null, c'est qu'il n'est pas authentifié, on bloque l'accès
            if (!$currentUser instanceof User) {
                throw $this->createAccessDeniedException('Utilisateur non authentifié.');
            }

            if (!in_array($requestType, $allowedRequestTypes, true)) {
                throw $this->createAccessDeniedException('Ce type de demande ne peut pas etre cree avec ce compte.');
            }

            $initialStatus = $requestType === AccessRequest::TYPE_FERMETURE
                ? AccessRequest::STATUS_EN_ATTENTE_VALIDATION
                : (in_array('ROLE_RH', $currentUser->getRoles(), true)
                    ? AccessRequest::STATUS_EN_ATTENTE_VALIDATION
                    : AccessRequest::STATUS_EN_ATTENTE_RH);

            $parentRequest = $formData->getParentRequest();
            $effectiveParentRequest = $parentRequest;

            // Verrou métier : empêcher une nouvelle ouverture concurrente pour le même agent
            if ($requestType === AccessRequest::TYPE_OUVERTURE) {
                $activeCurrent = $requestRepository->findActiveCurrentRequestForAgentIdentity(
                    (string) $formData->getFirstname(),
                    (string) $formData->getLastname(),
                    (string) $formData->getEmail()
                );

                if ($activeCurrent instanceof AccessRequest) {
                    $this->addFlash(
                        'warning',
                        sprintf(
                            'Une Demande active existe déjà pour cet agent (%s). Fermez-la ou modifiez-la avant de créer une nouvelle ouverture.',
                            $activeCurrent->getReference()
                        )
                    );

                    return $this->redirectToRoute('app_new_request');
                }
            }

            // Verrous métier sur modification / fermeture
            if (in_array($requestType, [AccessRequest::TYPE_MODIFICATION, AccessRequest::TYPE_FERMETURE], true) && $parentRequest instanceof AccessRequest) {
                $currentInChain = $requestRepository->findCurrentInChain($parentRequest);

                $isClosedChain = $currentInChain->getType() === AccessRequest::TYPE_FERMETURE
                    && $currentInChain->getStatus() === AccessRequest::STATUS_TRAITEE;

                // Empêcher une modification sur chaîne clôturée
                if ($requestType === AccessRequest::TYPE_MODIFICATION && $isClosedChain) {
                    $this->addFlash(
                        'warning',
                        sprintf(
                            'Modification impossible : la Demande est déjà clôturée (%s).',
                            $currentInChain->getReference()
                        )
                    );

                    return $this->redirectToRoute('app_new_request');
                }

                // Empêcher une fermeture en double
                if ($requestType === AccessRequest::TYPE_FERMETURE && $isClosedChain) {
                    $this->addFlash(
                        'warning',
                        sprintf(
                            'Fermeture impossible : la Demande est déjà clôturée (%s).',
                            $currentInChain->getReference()
                        )
                    );

                    return $this->redirectToRoute('app_new_request');
                }

                // Toujours rattacher la nouvelle demande au dernier état courant de la chaîne
                $effectiveParentRequest = $currentInChain;
            }

            try {
                $createdRequest = $requestCreationService->createAtomically(
                    $formData,
                    $currentUser,
                    $requestType,
                    $initialStatus,
                    $effectiveParentRequest
                );

                $messageBus->dispatch(new WorkflowNotificationMessage(
                    (int) $createdRequest->getId(),
                    trim((string) ($formData->getCommentary() ?? '')) !== ''
                        ? (string) $formData->getCommentary()
                        : 'Nouvelle demande créée.'
                ));
            } catch (\Throwable) {
                $this->addFlash('danger', 'La création de la demande a échoué. Aucune donnée n\'a été enregistrée.');

                return $this->redirectToRoute('app_new_request');
            }

            return $this->redirectToRoute('app_new_request', ['saved' => 1]);
        }

        return $this->render('new_request/index.html.twig', [
            'saved' => $request->query->getBoolean('saved'),
            'form' => $form->createView(),
            'isServiceTechniqueUser' => $isServiceTechniqueUser,
        ]);
    }

    
   
}
