<?php

namespace App\MessageHandler;

use App\Message\WorkflowNotificationMessage;
use App\Repository\RequestRepository;
use App\Service\Workflow\WorkflowNotificationService;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class WorkflowNotificationMessageHandler 
{
    public function __construct(
        private RequestRepository $requestRepository,
        private WorkflowNotificationService $notificationService,
    ) {}
    public function __invoke (WorkflowNotificationMessage $message): void
    {
        $request = $this->requestRepository->find($message->requestId);

        if ($request === null) {
            return;
        }

        $this->notificationService->notifyAllActors($request, $message->comment);
    }
}
