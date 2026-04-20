<?php

declare(strict_types=1);

namespace App\Service;

use App\Entity\Agent;
use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Form\Model\NewRequestData;
use App\Repository\AgentRepository;
use App\Repository\WorkflowTransitionConfigRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RequestCreationService
{
    private const DEFAULT_WORKFLOW_CODE = 'default_access';

    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
        private AgentRepository $agentRepository,
    ) {}

    /**
     * @param callable(string):void|null $failureHook de test pour simuler une panne intermédiaire.
     */
    public function createAtomically(
        NewRequestData $formData,
        User $currentUser,
        string $requestType,
        string $initialStatus,
        ?AccessRequest $effectiveParentRequest,
        ?callable $failureHook = null,
    ): AccessRequest {
        $connection = $this->em->getConnection();
        $connection->beginTransaction();

        try {
            $newRequest = new AccessRequest();

            $newRequest
                ->setType($requestType)
                ->setStatus($initialStatus)
                ->setCommentary($formData->getCommentary())
                ->setCreationDate(new \DateTimeImmutable())
                ->setUpdateDate(new \DateTimeImmutable())
                ->setAuthor($currentUser);

            if ($effectiveParentRequest instanceof AccessRequest) {
                $newRequest->setParentRequest($effectiveParentRequest);
            }

            $activeTransitions = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(SELF::DEFAULT_WORKFLOW_CODE);
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
            $agent = $effectiveParentRequest?->getAgent();

            if (!$agent instanceof Agent) {
                $agent = $this->agentRepository->findOneByIdentity(
                    (string) $formData->getFirstname(),
                    (string) $formData->getLastname(),
                    $formData->getEmail()
                );
            }

            if (!$agent instanceof Agent) {
                $agent = new Agent();
                $this->em->persist($agent);
            }

            $agent
                ->setCivility($formData->getCivility() ?? 'N/A')
                ->setFirstname($formData->getFirstname() ?? '')
                ->setLastname($formData->getLastname() ?? '')
                ->setJobTitle($formData->getJobTitle() ?? '')
                ->setEmail($formData->getEmail())
                ->setService($formData->getService());

            $newRequest->setAgent($agent);

            if ($failureHook !== null) {
                $failureHook('after_agent');
            }

            $newRequest->setArrivalDate($formData->getArrivalDate());
            $newRequest->setDepartureDate($formData->getDepartureDate());

            if ($requestType === AccessRequest::TYPE_FERMETURE) {
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

            $this->em->persist($newRequest);

            if ($failureHook !== null) {
                $failureHook('after_request');
            }

            if (
                ($requestType === AccessRequest::TYPE_MODIFICATION || $requestType === AccessRequest::TYPE_FERMETURE) && $effectiveParentRequest instanceof AccessRequest
            ) {
                foreach ($effectiveParentRequest->getRequestId() as $parentHistory) {
                    $historyCopy = new WorkflowHistory();
                    $historyCopy
                        ->setRequest($newRequest)
                        ->setUser($parentHistory->getUser() ?? $currentUser)
                        ->setOldStatus($parentHistory->getOldStatus() ?? '')
                        ->setNewStatus($parentHistory->getNewStatus() ?? '')
                        ->setCommentary($parentHistory->getCommentary() ?? '')
                        ->setDate($parentHistory->getDate() ?? new \DateTimeImmutable());

                    $this->em->persist($historyCopy);
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
            $this->em->persist($creationHistory);

            if ($failureHook !== null) {
                $failureHook('after_history');
            }

            $this->em->flush();
            $connection->commit();

            return $newRequest;
        } catch (\Throwable $e) {
            if ($connection->isTransactionActive()) {
                $connection->rollback();
            }
            $this->em->clear();
            throw $e;
        }
    }
}
