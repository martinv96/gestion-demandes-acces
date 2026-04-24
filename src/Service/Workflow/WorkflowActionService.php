<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use Doctrine\ORM\EntityManagerInterface;
use LogicException;

class WorkflowActionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowStateResolver $stateResolver,
        private WorkflowPermissionChecker $permissionChecker,
        private WorkflowBlockageHelper $blockageHelper,
        private WorkflowNotificationService $notificationService,
    ) {
    }

    public function validate(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        if ($this->stateResolver->isParallelServicePhase((string) ($request->getStatus() ?? ''))) {
            $this->applyParallelValidation($request, $user, $comment);

            return;
        }

        $transition = $this->stateResolver->resolveTransition($request, $user, 'validate');

        if ($transition === null || !in_array($transition['role'], $user->getRoles(), true)) {
            throw new LogicException('Transition de validation non autorisee pour ce role ou ce statut.');
        }

        $this->applyTransition($request, $user, $transition['next'], $comment);
    }

    public function refuse(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de refus.');
        }

        $transition = $this->stateResolver->resolveTransition($request, $user, 'refuse');

        if ($transition === null || !in_array($transition['role'], $user->getRoles(), true)) {
            throw new LogicException('Transition de refus non autorisee pour ce role ou ce statut.');
        }

        $this->applyTransition($request, $user, $transition['next'], $comment);
    }

    public function undoLastDecision(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire pour annuler une decision.');
        }

        $latestHistory = $this->stateResolver->getLatestHistory($request);
        if (!$latestHistory instanceof WorkflowHistory) {
            throw new LogicException('Aucune decision recente a annuler.');
        }

        if (!$this->permissionChecker->canUndoLastDecision($request, $user)) {
            throw new LogicException('Annulation de decision non autorisee.');
        }

        $previousStatus = trim((string) $latestHistory->getOldStatus());
        if ($previousStatus === '') {
            throw new LogicException('Statut precedent introuvable.');
        }

        $this->applyTransition($request, $user, $previousStatus, 'Annulation de decision : ' . trim($comment));
    }

    public function finalizeClosureByAnyUser(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        if (!$this->permissionChecker->canFinalizeClosureByAnyUser($request)) {
            throw new LogicException('Cette demande de fermeture ne peut pas etre validee pour le moment.');
        }

        $this->applyTransition($request, $user, AccessRequest::STATUS_TRAITEE, $comment);
    }

    public function unblockByRh(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire pour debloquer.');
        }

        if (!$this->permissionChecker->canUnblockByRh($request, $user)) {
            throw new LogicException('Deblocage non autorise pour cette demande.');
        }

        $currentStatus = (string) ($request->getStatus() ?? '');
        $nextStatus = $this->blockageHelper->resolveNextValidationStatus($request, $currentStatus);

        if ($nextStatus === null || $nextStatus === '') {
            throw new LogicException('Transition de deblocage introuvable.');
        }

        $this->applyTransition(
            $request,
            $user,
            $nextStatus,
            'Deblocage RH (compte valideur inactif/supprime) : ' . trim($comment)
        );
    }

    private function applyTransition(AccessRequest $request, User $user, string $newStatus, string $comment): void
    {
        $history = new WorkflowHistory();
        $history
            ->setRequest($request)
            ->setUser($user)
            ->setOldStatus($request->getStatus() ?? '')
            ->setNewStatus($newStatus)
            ->setCommentary($comment)
            ->setDate(new \DateTimeImmutable());

        $request->setStatus($newStatus);
        $request->setUpdateDate(new \DateTimeImmutable());

        $this->em->persist($history);
        $this->em->flush();

        $this->notificationService->notifyAllActors($request, $comment);
    }

    private function applyParallelValidation(AccessRequest $request, User $user, string $comment): void
    {
        $requiredRoles = $this->stateResolver->getParallelRequiredRoles($request);
        $actorRole = $this->stateResolver->resolveParallelRoleForUser($request, $user, $requiredRoles);

        if ($actorRole === null) {
            throw new LogicException('Transition de validation non autorisee pour ce role ou ce statut.');
        }

        if ($this->stateResolver->hasRoleAlreadyValidated($request, $actorRole)) {
            throw new LogicException('Ce service a deja valide cette demande.');
        }

        $validatedRoles = $this->stateResolver->getValidatedParallelRoles($request, $requiredRoles);
        $validatedRoles[] = $actorRole;
        $validatedRoles = array_values(array_unique($validatedRoles));

        $allValidated = $requiredRoles === [] || array_diff($requiredRoles, $validatedRoles) === [];

        $nextStatus = $allValidated ? AccessRequest::STATUS_TRAITEE : AccessRequest::STATUS_EN_ATTENTE_VALIDATION;

        if ($allValidated && $this->isClosureRequest($request) && $this->hasPendingMaterialReturns($request)) {
            $nextStatus = AccessRequest::STATUS_EN_ATTENTE_VALIDATION;
        }

        $this->applyTransition($request, $user, $nextStatus, $comment);
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
