<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Repository\RequestRepository;
use App\Repository\WorkflowHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Déclenche les relances automatiques pour les demandes restées en attente.
 */
final class WorkflowReminderService
{
    // Une première relance est envoyée après trois jours sans changement de statut.
    private const ESCALATION_DELAY_DAYS = 3;

    public function __construct(
        private RequestRepository $requestRepository,
        private WorkflowHistoryRepository $workflowHistoryRepository,
        private WorkflowNotificationService $workflowNotificationService,
        private EntityManagerInterface $entityManager,
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function processAutomaticReminders(): int
    {
        // Le repository ne retourne que les demandes encore éligibles à une relance.
        $requests = $this->requestRepository->findPendingForAutomaticReminder();

        if ($requests === []) {
            return 0;
        }

        // Un seul chargement de l'historique évite une requête supplémentaire pour chaque demande.
        $latestHistories = $this->workflowHistoryRepository->findLatestByRequests($requests);
        $now = new \DateTimeImmutable();
        $processedCount = 0;

        foreach ($requests as $request) {
            $requestId = $request->getId();

            if ($requestId === null) {
                continue;
            }

            // Le délai commence au dernier changement de statut, ou à la création si aucun historique n'existe.
            $statusChangedAt = $latestHistories[$requestId]->getDate() ?? $request->getCreationDate();

            if (!$statusChangedAt instanceof \DateTimeImmutable) {
                continue;
            }

            // Conversion en jours complets pour appliquer le délai métier défini ci-dessus.
            $ageInDays = (int) floor(($now->getTimestamp() - $statusChangedAt->getTimestamp()) / 86400);

            $lastReminderAt = $request->getLastReminderAt();
            $effectiveReminderCount = 0;

            // Une relance antérieure à un changement de statut ne compte plus pour la nouvelle étape.
            if ($lastReminderAt instanceof \DateTimeImmutable && $lastReminderAt >= $statusChangedAt) {
                $effectiveReminderCount = $request->getReminderCount();
            }

            // Une seule relance automatique est envoyée par étape de workflow.
            if ($effectiveReminderCount < 1 && $ageInDays >= self::ESCALATION_DELAY_DAYS) {
                $this->workflowNotificationService->sendReminder(
                    $request,
                    sprintf(
                        'Rappel automatique : la demande %s est en attente depuis %d jour(s) et nécessite votre validation.',
                        $request->getReference(),
                        $ageInDays
                    )
                );

                $request
                    ->setLastReminderAt($now)
                    ->setReminderCount(1);

                $processedCount++;
            }
        }

        // Enregistre en une fois les dates et compteurs de toutes les demandes relancées.
        $this->entityManager->flush();

        $this->logger?->info('Traitement des relances automatiques terminé.',[
            'processed_count' => $processedCount,
        ]);

        return $processedCount;
    }
}