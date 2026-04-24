<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Entity\WorkflowHistory;

class WorkflowPermissionChecker
{
    public function __construct(
        private WorkflowStateResolver $stateResolver,
        private WorkflowBlockageHelper $blockageHelper
    ) {}

    public function canValidate(AccessRequest $request, User $user): bool
    {
        $transition = $this->stateResolver->resolveTransition($request, $user, 'validate');

        return $transition !== null && in_array($transition['role'], $user->getRoles(), true);
    }

    public function canRefuse(AccessRequest $request, User $user): bool
    {
        $transition = $this->stateResolver->resolveTransition($request, $user, 'refuse');

        return $transition !== null && in_array($transition['role'], $user->getRoles(), true);
    }

    public function canUndoLastDecision(AccessRequest $request, User $user): bool
    {
        $latestHistory = $this->stateResolver->getLatestHistory($request);

        if (!$latestHistory instanceof WorkflowHistory) {
            return false;
        }

        $currentStatus = (string) ($request->getStatus() ?? '');
        $latestNewStatus = (string) ($latestHistory->getNewStatus() ?? '');

        if ($currentStatus === '' || $latestNewStatus === '' || $currentStatus !== $latestNewStatus) {
            return false;
        }

        if ($this->isClosureRequest($request)) {
            return $currentStatus === AccessRequest::STATUS_TRAITEE;
        }

        $latestUser = $latestHistory->getUser();
        if (!$latestUser instanceof User) {
            return false;
        }

        return $this->stateResolver->shareWorkflowActor($user, $latestUser);
    }

    public function canFinalizeClosureByAnyUser(AccessRequest $request): bool
    {
        if (!$this->isClosureRequest($request)) {
            return false;
        }

        if (!$this->stateResolver->isParallelServicePhase((string) ($request->getStatus() ?? ''))) {
            return false;
        }

        $requiredRoles = $this->stateResolver->getParallelRequiredRoles($request);
        if ($requiredRoles !== [] && !$this->stateResolver->areAllParallelRolesValidated($request, $requiredRoles)) {
            return false;
        }

        return !$this->hasPendingMaterialReturns($request);
    }

    public function canUnblockByRh(AccessRequest $request, User $user): bool
    {
        return $this->blockageHelper->canUnblockByRh($request, $user);
    }

    public function canEditAfterRefusal(AccessRequest $request, User $user): bool
    {
        $status = (string) ($request->getStatus() ?? '');

        if (str_starts_with($status, 'refusee_')) {
            return in_array('ROLE_RH', $user->getRoles(), true);
        }

        if ($this->stateResolver->isParallelServicePhase($status)) {
            return $this->stateResolver->resolveParallelRoleForUser($request, $user) !== null;
        }

        return false;
    }

    public function isBlockedByMissingValidator(AccessRequest $request): bool
    {
        return $this->blockageHelper->isBlockedByMissingValidator($request);
    }

    public function getMissingValidatorLabel(AccessRequest $request): string
    {
        return $this->blockageHelper->getMissingValidatorLabel($request);
    }

    private function isClosureRequest(AccessRequest $request): bool
    {
        try {
            return $request->getType() === AccessRequest::TYPE_FERMETURE;
        } catch (\Error) {
            return false;
        }
    }

    private function hasPendingMaterialReturns(AccessRequest $request): bool
    {
        foreach ($request->getRessources() as $ressource) {
            if ($ressource->getCategory() === 'materiel') {
                return true;
            }
        }

        return false;
    }
}