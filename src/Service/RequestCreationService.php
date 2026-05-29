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
use Symfony\Component\HttpFoundation\File\UploadedFile;

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
        ?UploadedFile $pieceJointeFile = null,
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
                ->setPhoneTypes($formData->getPhoneTypes())
                ->setCreationDate(new \DateTimeImmutable())
                ->setUpdateDate(new \DateTimeImmutable())
                ->setAuthor($currentUser);

            if ($effectiveParentRequest instanceof AccessRequest) {
                $newRequest->setParentRequest($effectiveParentRequest);
            }

            $activeTransitions = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(SELF::DEFAULT_WORKFLOW_CODE);
            $workflowSnapshot = array_map(
                fn($transition): array => $this->normalizeWorkflowSnapshotRow([
                    'workflowCode' => (string) $transition->getWorkflowCode(),
                    'stepOrder' => (int) $transition->getStepOrder(),
                    'action' => (string) $transition->getAction(),
                    'fromStatus' => (string) $transition->getFromStatus(),
                    'toStatus' => (string) $transition->getToStatus(),
                    'requiredRole' => (string) $transition->getRequiredRole(),
                ]),
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
                ->setClothingSize($formData->getClothingSize())
                ->setShoeSize($formData->getShoeSize())
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


            if ($pieceJointeFile instanceof UploadedFile) {
                $originalFilename = pathinfo($pieceJointeFile->getClientOriginalName(), PATHINFO_FILENAME);
                $safeFilename = transliterator_transliterate('Any-Latin; Latin-ASCII; [^A-Za-z0-9_] remove; Lower()', $originalFilename);
                $newFilename = $safeFilename . '-' . uniqid() . '.' . $pieceJointeFile->guessExtension();

                try {
                    $pieceJointeFile->move(
                        __DIR__ . '/../../public/uploads/pieces_jointes',
                        $newFilename
                    );
                    $newRequest->setPieceJointe($newFilename);
                } catch (\Exception $e) {
                    throw new \RuntimeException('Erreur upload pièce jointe : ' . $e->getMessage());
                }
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

    /**
     * @param array{workflowCode: string, stepOrder: int, action: string, fromStatus: string, toStatus: string, requiredRole: string} $row
     * @return array{workflowCode: string, stepOrder: int, action: string, fromStatus: string, toStatus: string, requiredRole: string}
     */
    private function normalizeWorkflowSnapshotRow(array $row): array
    {
        $row['action'] = strtolower(trim($row['action']));
        if ($row['action'] === 'rufuse') {
            $row['action'] = 'refuse';
        }

        $row['fromStatus'] = str_replace(' ', '_', trim($row['fromStatus']));
        $row['toStatus'] = str_replace(' ', '_', trim($row['toStatus']));
        $row['requiredRole'] = strtoupper(trim($row['requiredRole']));

        if (
            $row['action'] === 'validate'
            && $row['requiredRole'] === 'ROLE_RH'
            && $row['fromStatus'] === AccessRequest::STATUS_EN_ATTENTE_RH
        ) {
            $row['toStatus'] = AccessRequest::STATUS_EN_ATTENTE_VALIDATION;
        }

        if (
            $row['requiredRole'] !== ''
            && $row['requiredRole'] !== 'ROLE_RH'
            && str_starts_with($row['fromStatus'], 'en_attente_')
        ) {
            $row['fromStatus'] = AccessRequest::STATUS_EN_ATTENTE_VALIDATION;

            if ($row['action'] === 'refuse') {
                $row['toStatus'] = sprintf(
                    'refusee_%s',
                    strtolower(preg_replace('/^ROLE_/', '', $row['requiredRole']) ?? 'service')
                );
            }
        }

        return $row;
    }
}
