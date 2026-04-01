<?php

namespace App\Controller;

use App\Entity\Agent;
use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Form\Model\NewRequestData;
use App\Form\NewRequestType;
use App\Repository\WorkflowTransitionConfigRepository;
use App\Repository\RequestRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

final class NewRequestController extends AbstractController
{

    private const DEFAULT_WORKFLOW_CODE = 'default_access';
    // route pour créer une nouvelle demande d'accès
    #[Route('/new/request', name: 'app_new_request', methods: ['GET', 'POST'])]
    public function index(Request $request, EntityManagerInterface $entityManager, RequestRepository $requestRepository, WorkflowTransitionConfigRepository $workflowTransitionConfigRepository): Response
    {
        $formData = new NewRequestData();
        $form = $this->createForm(NewRequestType::class, $formData);

        $form->handleRequest($request);

        // Si le formulaire est soumis et valide, créer la demande d'accès
        if ($form->isSubmitted() && $form->isValid()) {
            $requestType = $formData->getType() ?? 'ouverture';
            $initialStatus = ($requestType === 'modification' || $requestType === 'fermeture') ? 'traitee' : 'en_attente_rh';

            $newRequest = new AccessRequest();

            $newRequest
                ->setType($requestType)
                ->setStatus($initialStatus)
                ->setCommentary($formData->getCommentary())
                ->setCreationDate(new \DateTimeImmutable())
                ->setUpdateDate(new \DateTimeImmutable());

            $activeTransitions = $workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);

            $workflowSnapshot = array_map(
                static fn($transition): array => [
                    'workflowCode' => (string) $transition->getWorkflowCode(),
                    'stepOrder' => (int) $transition->getStepOrder(),
                    'action' => (string) $transition->getAction(),
                    'fromStatus' => (string) $transition->getFromStatus(),
                    'toStatus' => (string) $transition->getToStatus(),
                    'requiredRole' => (string) $transition->getRequiredRole(),
                ],
                $activeTransitions
            );

            $newRequest->setWorkflowSnapshot($workflowSnapshot !== [] ? $workflowSnapshot : null);

            $currentUser = $this->getUser();

            // si l'user est null, c'est qu'il n'est pas authentifié, on bloque l'accès
            if (!$currentUser instanceof User) {
                throw $this->createAccessDeniedException('Utilisateur non authentifié.');
            }

            $newRequest->setAuthor($currentUser);

            $agent = new Agent();
            $agent
                ->setCivility($formData->getCivility() ?? 'N/A')
                ->setFirstname($formData->getFirstname() ?? '')
                ->setLastname($formData->getLastname() ?? '')
                ->setJobTitle($formData->getJobTitle() ?? '')
                ->setEmail($formData->getEmail())
                ->setService($formData->getService());

            $entityManager->persist($agent);
            $newRequest->setAgent($agent);

            $parentRequest = $formData->getParentRequest();
            if ($parentRequest instanceof AccessRequest) {
                $newRequest->setParentRequest($parentRequest);
            }

            $effectiveParentRequest = $parentRequest;

            // Verrou métier : empêcher une nouvelle ouverture concurrente pour le même agent
            if ($requestType === 'ouverture') {
                $activeCurrent = $requestRepository->findActiveCurrentRequestForAgentIdentity(
                    (string) $formData->getFirstname(),
                    (string) $formData->getLastname(),
                    (string) $formData->getEmail()
                );

                if ($activeCurrent instanceof AccessRequest) {
                    $this->addFlash(
                        'warning',
                        sprintf(
                            'Une chaîne active existe déjà pour cet agent (%s). Fermez-la ou modifiez-la avant de créer une nouvelle ouverture.',
                            $activeCurrent->getReference()
                        )
                    );

                    return $this->redirectToRoute('app_new_request');
                }
            }

            // Verrous métier sur modification / fermeture
            if (in_array($requestType, ['modification', 'fermeture'], true) && $parentRequest instanceof AccessRequest) {
                $currentInChain = $requestRepository->findCurrentInChain($parentRequest);

                $isClosedChain = $currentInChain->getType() === 'fermeture'
                    && $currentInChain->getStatus() === 'traitee';

                // Empêcher une modification sur chaîne clôturée
                if ($requestType === 'modification' && $isClosedChain) {
                    $this->addFlash(
                        'warning',
                        sprintf(
                            'Modification impossible : la chaîne est déjà clôturée (%s).',
                            $currentInChain->getReference()
                        )
                    );

                    return $this->redirectToRoute('app_new_request');
                }

                // Empêcher une fermeture en double
                if ($requestType === 'fermeture' && $isClosedChain) {
                    $this->addFlash(
                        'warning',
                        sprintf(
                            'Fermeture impossible : la chaîne est déjà clôturée (%s).',
                            $currentInChain->getReference()
                        )
                    );

                    return $this->redirectToRoute('app_new_request');
                }

                // Toujours rattacher la nouvelle demande au dernier état courant de la chaîne
                $newRequest->setParentRequest($currentInChain);
                $effectiveParentRequest = $currentInChain;
            }


            // si date arrivée donnée, on la set, sinon null (selon ouverture ou fermeture)
            // ouverture : date arrivée obligatoire, sinon la validation échouera (validation dans NewRequestData)
            if ($formData->getArrivalDate() instanceof \DateTime) {
                $newRequest->setArrivalDate($formData->getArrivalDate());
            } else {
                $newRequest->setArrivalDate(new \DateTime());
            }

            if ($formData->getDepartureDate() instanceof \DateTime) {
                $newRequest->setDepartureDate($formData->getDepartureDate());
            }

            // Si c'est une fermeture, on copie les ressources de la demande d'origine, sinon on prend celles du formulaire
            if ($requestType === 'fermeture') {
                if ($effectiveParentRequest instanceof AccessRequest) {
                    foreach ($effectiveParentRequest->getRessources() as $ressource) {
                        $newRequest->addRessource($ressource);
                    }
                }
            } else {
                foreach ($formData->getLogiciels() as $logiciel) {
                    $logiciel->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $newRequest->addRessource($logiciel);
                }

                foreach ($formData->getMateriels() as $materiel) {
                    $materiel->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
                    $newRequest->addRessource($materiel);
                }
            }

            $entityManager->persist($newRequest);

            // Si c'est une modification ou une fermeture, on copie l'historique de la demande d'origine
            if (($requestType === 'modification' || $requestType === 'fermeture') && $effectiveParentRequest instanceof AccessRequest) {
                foreach ($effectiveParentRequest->getRequestId() as $parentHistory) {
                    $historyCopy = new WorkflowHistory();
                    $historyCopy
                        ->setRequest($newRequest)
                        ->setUser($parentHistory->getUser() ?? $currentUser)
                        ->setOldStatus($parentHistory->getOldStatus() ?? '')
                        ->setNewStatus($parentHistory->getNewStatus() ?? '')
                        ->setCommentary($parentHistory->getCommentary() ?? '')
                        ->setDate($parentHistory->getDate() ?? new \DateTimeImmutable());

                    $entityManager->persist($historyCopy);
                }
            }

            $creationHistory = new WorkflowHistory();
            $creationHistory
                ->setRequest($newRequest)
                ->setUser($currentUser)
                ->setOldStatus($effectiveParentRequest instanceof AccessRequest ? ($effectiveParentRequest->getStatus() ?? '') : '')
                ->setNewStatus($newRequest->getStatus() ?? '')
                ->setCommentary($formData->getCommentary() ?? '')
                ->setDate(new \DateTimeImmutable());

            $entityManager->persist($creationHistory);

            $entityManager->flush();

            return $this->redirectToRoute('app_new_request', ['saved' => 1]);
        }

        return $this->render('new_request/index.html.twig', [
            'saved' => $request->query->getBoolean('saved'),
            'form' => $form->createView(),
        ]);
    }

    
}
