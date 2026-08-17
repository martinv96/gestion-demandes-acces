<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Repository\RequestRepository;
use App\Repository\WorkflowHistoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final class WorkflowReminderService
{
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

            $statusChangedAt = $latestHistories[$requestId]->getDate() ?? $request->getCreationDate();

            if (!$statusChangedAt instanceof \DateTimeImmutable) {
                continue;
            }

            $ageInDays = (int) floor(($now->getTimestamp() - $statusChangedAt->getTimestamp()) / 86400);

            $lastReminderAt = $request->getLastReminderAt();
            $effectiveReminderCount = 0;

            if ($lastReminderAt instanceof \DateTimeImmutable && $lastReminderAt >= $statusChangedAt) {
                $effectiveReminderCount = $request->getReminderCount();
            }

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

        $this->entityManager->flush();

        $this->logger?->info('Traitement des relances automatiques terminé.',[
            'processed_count' => $processedCount,
        ]);

        return $processedCount;
    }
}