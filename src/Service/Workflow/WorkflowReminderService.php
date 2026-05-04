<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Repository\RequestRepository;
use App\Repository\WorkflowHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class WorkflowReminderService
{
    private const FIRST_REMINDER_DELAY_HOURS = 24;
    private const SECOND_REMINDER_DELAY_HOURS = 48;
    private const ESCALATION_DELAY_HOURS = 72;

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
        $requests = $this->requestRepository->findPendingForAutomaticReminder();

        if ($requests === []) {
            return 0;
        }

        $latestHistories = $this->workflowHistoryRepository->findLatestByRequests($requests);
        $now = new \DateTimeImmutable();
        $processedCount = 0;

        foreach ($requests as $request) {
            $requestId = $request->getId();

            if ($requestId === null) {
                continue;
            }

            $statusChangedAt = $latestHistories[$requestId]->getDate ?? $request->getCreationDate();

            if (!$statusChangedAt instanceof \DateTimeImmutable) {
                continue;
            }

            $ageInHours = (int) floor(($now->getTimestamp() - $statusChangedAt->getTimestamp()) / 3600);

            $lastReminderAt = $request->getLastReminderAt();
            $effectiveReminderCount = 0;

            if ($lastReminderAt instanceof \DateTimeImmutable && $lastReminderAt >= $statusChangedAt) {
                $effectiveReminderCount = $request->getReminderCount();
            }

            $escalatedAt = $request->getEscalatedAt();
            $alreadyEscalatedForCurrentStatus = $escalatedAt instanceof \DateTimeImmutable && $escalatedAt >= $statusChangedAt;

            if (!$alreadyEscalatedForCurrentStatus && $ageInHours >= self::ESCALATION_DELAY_HOURS) {
                $this->workflowNotificationService->sendEscalation(
                    $request,
                    sprintf('La demande %s est bloquée au statut "%s" depuis %d heures.',
                    $request->getReference(),
                    (string) $request->getStatus(),
                    $ageInHours
                    )
                );

                $request->setEscalatedAt($now);
                $processedCount++;

                continue;
            }

            if ($effectiveReminderCount < 2 && $ageInHours >= self::SECOND_REMINDER_DELAY_HOURS) {
                $this->workflowNotificationService->sendReminder(
                    $request,
                    sprintf('Deuxième relance automatique : la demande %s est en attente depuis %d heures.',
                    $request->getReference(),
                    $ageInHours
                    )
                );

                $request
                    ->setLastReminderAt($now)
                    ->setReminderCount(2);
                
                    $processedCount++;

                    continue;
            }

            if ($effectiveReminderCount < 1 && $ageInHours >= self::FIRST_REMINDER_DELAY_HOURS) {
                $this->workflowNotificationService->sendReminder(
                    $request,
                    sprintf(
                        'Première relance automatique : la demande %s est en attente depuis %d heures.',
                        $request->getReference(),
                        $ageInHours
                    )
                );

                $request
                    ->setLastReminderAt($now)
                    ->setReminderCount(1);

                $processedCount++;
            }
        }

        $this->entityManager->flush();

        $this->logger?->info('Traitement des relances automatiques terminé.',[
            'processed_count' => $processedCount,
        ]);

        return $processedCount;
    }
}