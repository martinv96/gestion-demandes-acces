<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Service\Workflow\WorkflowActionService;
use App\Service\Workflow\WorkflowNotificationService;
use App\Service\Workflow\WorkflowPermissionChecker;
use Symfony\Component\Messenger\MessageBusInterface;

class WorkflowService
{
    public const LABELS = [
        AccessRequest::STATUS_EN_ATTENTE_RH => 'En attente RH',
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION => 'En attente validations services',
        AccessRequest::STATUS_EN_ATTENTE_ST => 'En attente ST',
        AccessRequest::STATUS_EN_ATTENTE_DSI => 'En attente DSI',
        AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT => 'En attente traitement',
        AccessRequest::STATUS_TRAITEE => 'Traitee',
        'refusee_rh' => 'Refusee par RH',
        'refusee_st' => 'Refusee par ST',
        'refusee_dsi' => 'Refusee par DSI',
    ];

    public function __construct(
        private WorkflowActionService $actionService,
        private WorkflowPermissionChecker $permissionChecker,
        private WorkflowNotificationService $notificationService,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function canValidate(AccessRequest $request, User $user): bool
    {
        return $this->permissionChecker->canValidate($request, $user);
    }

    public function canRefuse(AccessRequest $request, User $user): bool
    {
        return $this->permissionChecker->canRefuse($request, $user);
    }

    public function canUndoLastDecision(AccessRequest $request, User $user): bool
    {
        return $this->permissionChecker->canUndoLastDecision($request, $user);
    }

    public function validate(AccessRequest $request, User $user, string $comment): void
    {
        $this->actionService->validate($request, $user, $comment);
    }

    public function canFinalizeClosureByAnyUser(AccessRequest $request): bool
    {
        return $this->permissionChecker->canFinalizeClosureByAnyUser($request);
    }

    public function finalizeClosureByAnyUser(AccessRequest $request, User $user, string $comment): void
    {
        $this->actionService->finalizeClosureByAnyUser($request, $user, $comment);
    }

    public function refuse(AccessRequest $request, User $user, string $comment): void
    {
        $this->actionService->refuse($request, $user, $comment);
    }

    public function undoLastDecision(AccessRequest $request, User $user, string $comment): void
    {
        $this->actionService->undoLastDecision($request, $user, $comment);
    }

    public static function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public function canEditAfterRefusal(AccessRequest $request, User $user): bool
    {
        return $this->permissionChecker->canEditAfterRefusal($request, $user);
    }

    public function canUnblockByRh(AccessRequest $request, User $user): bool
    {
        return $this->permissionChecker->canUnblockByRh($request, $user);
    }

    public function unblockByRh(AccessRequest $request, User $user, string $comment): void
    {
        $this->actionService->unblockByRh($request, $user, $comment);
    }

    public function isBlockedByMissingValidator(AccessRequest $request): bool
    {
        return $this->permissionChecker->isBlockedByMissingValidator($request);
    }

    public function getMissingValidatorLabel(AccessRequest $request): string
    {
        return $this->permissionChecker->getMissingValidatorLabel($request);
    }

    public function notifyAllActors(AccessRequest $request, string $comment): void
    {
        $this->messageBus->dispatch(new \App\Message\WorkflowNotificationMessage(
            $request->getId(),
            $comment,
        ));
    }
}
