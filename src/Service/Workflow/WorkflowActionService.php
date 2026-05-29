<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use LogicException;

class WorkflowActionService
{
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowStateResolver $stateResolver,
        private WorkflowPermissionChecker $permissionChecker,
        private WorkflowBlockageHelper $blockageHelper,
        private WorkflowNotificationService $notificationService,
        private MessageBusInterface $messageBus,
    ) {
    }

    public function validate(AccessRequest $request, User $user, string $comment, $forcedRole = null): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        if ($this->stateResolver->isParallelServicePhase((string) ($request->getStatus() ?? ''))) {
            $this->applyParallelValidation($request, $user, $comment, $forcedRole);
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
        // Le commentaire n'est PAS obligatoire pour annuler une décision côté services

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

    /**
     * Annule la dernière décision pour un rôle/service donné (undo par service)
     */
    public function undoLastDecisionForRole(AccessRequest $request, User $user, string $role, string $comment): void
    {
        $history = $this->stateResolver->getLatestHistoryForRole($request, $role);
        if (!$history instanceof WorkflowHistory) {
            throw new LogicException('Aucune décision récente à annuler pour ce service.');
        }
        if (!$this->permissionChecker->canUndoLastDecisionForRole($request, $user, $role)) {
            throw new LogicException('Annulation de décision non autorisée pour ce service.');
        }
        $previousStatus = trim((string) $history->getOldStatus());
        if ($previousStatus === '') {
            throw new LogicException('Statut précédent introuvable.');
        }
        // On annule en créant une nouvelle entrée d’historique
        $this->applyTransition($request, $user, $previousStatus, 'Annulation de décision pour ' . $role . ' : ' . trim($comment));
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

    private function applyTransition(AccessRequest $request, User $user, string $newStatus, string $comment, ?string $validatedRole = null): void
    {
        $history = new WorkflowHistory();
        $history
            ->setRequest($request)
            ->setUser($user)
            ->setOldStatus($request->getStatus() ?? '')
            ->setNewStatus($newStatus)
            ->setCommentary($comment)
            ->setDate(new \DateTimeImmutable());
        if ($validatedRole !== null) {
            $history->setValidatedRole($validatedRole);
        }

        $request->setStatus($newStatus);
        $request->setUpdateDate(new \DateTimeImmutable());
        $request->addRequestId($history);

        $this->em->persist($history);
        $this->em->flush();

        $this->messageBus->dispatch(new \App\Message\WorkflowNotificationMessage(
            $request->getId(),
            $comment,
        ));
    }

    private function applyParallelValidation(AccessRequest $request, User $user, string $comment, $forcedRole = null): void
    {
        $requiredRoles = $this->stateResolver->getParallelRequiredRoles($request);
        $actorRole = $forcedRole ?? $this->stateResolver->resolveParallelRoleForUser($request, $user, $requiredRoles);

        if ($actorRole === null || !in_array($actorRole, $requiredRoles, true)) {
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

        // Si validation admin (forcedRole), on enregistre explicitement le rôle validé
        $this->applyTransition($request, $user, $nextStatus, $comment, $forcedRole ? $actorRole : null);
    }
}