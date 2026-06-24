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
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            // Un admin peut valider toutes les étapes, sauf les demandes de fermeture (si tu veux l'autoriser aussi, retire la condition suivante)
            if ($this->isClosureRequest($request)) {
                return false;
            }
            return true;
        }

        if ($this->isClosureRequest($request)) {
            return false;
        }

        $transition = $this->stateResolver->resolveTransition($request, $user, 'validate');

        return $transition !== null && in_array($transition['role'], $user->getRoles(), true);
    }

    public function canRefuse(AccessRequest $request, User $user): bool
    {
        if ($this->isClosureRequest($request)) {
            return false;
        }

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

        // Empêche d'annuler une annulation de décision
        $comment = (string)($latestHistory->getCommentary() ?? '');
        if (str_starts_with($comment, 'Annulation de décision')) {
            return false;
        }

        if ($this->isClosureRequest($request)) {
            return $currentStatus === AccessRequest::STATUS_TRAITEE;
        }

        $latestUser = $latestHistory->getUser();
        if (!$latestUser instanceof User) {
            return false;
        }

        // Cas spécial : validation admin pour un service donné
        if (method_exists($latestHistory, 'getValidatedRole') && $latestHistory->getValidatedRole()) {
            $validatedRole = $latestHistory->getValidatedRole();
            if (in_array($validatedRole, $this->stateResolver->getWorkflowRoles($user), true)) {
                return true;
            }
        }

        return $this->stateResolver->shareWorkflowActor($user, $latestUser);
    }

    /**
     * Permet à un service d’annuler la dernière décision qui le concerne (undo par service)
     */
    public function canUndoLastDecisionForRole(AccessRequest $request, User $user, string $role): bool
    {
        // On récupère l’historique du service (toutes les actions de ce rôle)
        $histories = $request->getRequestId()->toArray();
        $serviceActions = [];
        foreach (array_reverse($histories) as $history) {
            if (!$history instanceof \App\Entity\WorkflowHistory) {
                continue;
            }
            // Cas admin : validatedRole
            $isForRole = false;
            if (method_exists($history, 'getValidatedRole') && $history->getValidatedRole()) {
                $isForRole = $history->getValidatedRole() === $role;
            } else {
                $historyUser = $history->getUser();
                if ($historyUser instanceof \App\Entity\User && in_array($role, $this->stateResolver->getWorkflowRoles($historyUser), true)) {
                    $isForRole = true;
                }
            }
            if (!$isForRole) {
                continue;
            }
            $comment = (string)($history->getCommentary() ?? '');
            if (str_starts_with($comment, 'Modification des informations :')) {
                continue;
            }
            // Si la dernière action est un undo, on ne peut plus annuler
            if (str_starts_with($comment, 'Annulation de décision')) {
                return false;
            }
            // Si la dernière action est une validation ou un refus, on peut annuler
            $newStatus = (string)($history->getNewStatus() ?? '');
            if (in_array($newStatus, [
                'en_attente_validation', 'en_attente_st', 'en_attente_dsi', 'en_attente_edu', 'en_attente_traitement', 'traitee',
                'refusee_rh', 'refusee_st', 'refusee_dsi', 'refusee_edu', 'refusee_fin',
            ], true)) {
                // Autorisé si l’utilisateur possède ce rôle
                return in_array($role, $this->stateResolver->getWorkflowRoles($user), true);
            }
            // Si c’est une autre action, on ne peut pas annuler
            break;
        }
        return false;
    }

    public function canFinalizeClosureByAnyUser(AccessRequest $request): bool
    {
        if (!$this->isClosureRequest($request)) {
            return false;
        }

        $currentStatus = (string) ($request->getStatus() ?? '');

        if (!in_array($currentStatus, [
            AccessRequest::STATUS_EN_ATTENTE_VALIDATION,
            AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT,
        ], true)) {
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
        if ($this->isInfoEditLocked($request, $user)) {
            return false;
        }

        $status = (string) ($request->getStatus() ?? '');

        if (str_starts_with($status, 'refusee_')) {
            return in_array('ROLE_RH', $user->getRoles(), true);
        }

        if ($this->isClosureRequest($request)) {
            return false;
        }

        if ($this->stateResolver->isParallelServicePhase($status)) {
            $userRole = $this->stateResolver->resolveParallelRoleForUser($request, $user);
            if ($userRole === null) {
                return false;
            }

            // Bloquer l'édition uniquement si CE service a déjà validé (pas les autres)
            return !$this->stateResolver->hasRoleAlreadyValidated($request, $userRole);
        }

        return false;
    }

    public function isInfoEditLocked(AccessRequest $request, User $user): bool
    {
        $histories = $request->getRequestId()->toArray();

        usort($histories, static function (mixed $a, mixed $b): int {
            if (!$a instanceof WorkflowHistory || !$b instanceof WorkflowHistory) {
                return 0;
            }

            $aDate = $a->getDate();
            $bDate = $b->getDate();

            if ($aDate == $bDate) {
                return ($b->getId() ?? 0) <=> ($a->getId() ?? 0);
            }

            return $bDate <=> $aDate;
        });

        foreach ($histories as $history) {
            if (!$history instanceof WorkflowHistory) {
                continue;
            }

            $comment = (string) ($history->getCommentary() ?? '');

            // L'annulation rouvre un nouveau cycle: l'édition redevient possible.
            if (str_starts_with($comment, 'Annulation de décision')) {
                return false;
            }

            if (!str_starts_with($comment, 'Modification des informations :')) {
                continue;
            }

            $historyUser = $history->getUser();
            if (!$historyUser instanceof User) {
                continue;
            }

            if ($this->stateResolver->shareWorkflowActor($user, $historyUser)) {
                return true;
            }
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