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
            throw new LogicException('Transition de validation non autorisée pour ce rôle ou ce statut.');
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
            throw new LogicException('Transition de refus non autorisée pour ce rôle ou ce statut.');
        }

        $this->applyTransition($request, $user, $transition['next'], $comment);
    }

    public function undoLastDecision(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire pour annuler une décision.');
        }

        $latestHistory = $this->stateResolver->getLatestHistory($request);
        if (!$latestHistory instanceof WorkflowHistory) {
            throw new LogicException('Aucune décision récente à annuler.');
        }

        if (!$this->permissionChecker->canUndoLastDecision($request, $user)) {
            throw new LogicException('Annulation de décision non autorisée.');
        }

        $previousStatus = trim((string) $latestHistory->getOldStatus());
        if ($previousStatus === '') {
            throw new LogicException('Statut précedent introuvable.');
        }

        $this->applyTransition($request, $user, $previousStatus, 'Annulation de décision : ' . trim($comment));
    }

    public function finalizeClosureByAnyUser(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        if (!$this->permissionChecker->canFinalizeClosureByAnyUser($request)) {
            throw new LogicException('Cette demande de fermeture ne peut pas être validée pour le moment.');
        }

        $this->applyTransition($request, $user, AccessRequest::STATUS_TRAITEE, $comment);
    }

    public function unblockByRh(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire pour débloquer.');
        }

        if (!$this->permissionChecker->canUnblockByRh($request, $user)) {
            throw new LogicException('Déblocage non autorisé pour cette demande.');
        }

        $currentStatus = (string) ($request->getStatus() ?? '');
        $nextStatus = $this->blockageHelper->resolveNextValidationStatus($request, $currentStatus);

        if ($nextStatus === null || $nextStatus === '') {
            throw new LogicException('Transition de déblocage introuvable.');
        }

        $this->applyTransition(
            $request,
            $user,
            $nextStatus,
            'Déblocage RH (compte valideur inactif/supprimé) : ' . trim($comment)
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
            throw new LogicException('Transition de validation non autorisée pour ce rôle ou ce statut.');
        }

        if ($this->stateResolver->hasRoleAlreadyValidated($request, $actorRole)) {
            throw new LogicException('Ce service à déjà validé cette demande.');
        }

        $validatedRoles = $this->stateResolver->getValidatedParallelRoles($request, $requiredRoles);
        $validatedRoles[] = $actorRole;
        $validatedRoles = array_values(array_unique($validatedRoles));

        $allValidated = $requiredRoles === [] || array_diff($requiredRoles, $validatedRoles) === [];

        $nextStatus = $allValidated ? AccessRequest::STATUS_TRAITEE : AccessRequest::STATUS_EN_ATTENTE_VALIDATION;

        $this->applyTransition($request, $user, $nextStatus, $comment);
    }
}
