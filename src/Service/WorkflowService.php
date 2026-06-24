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

    public function canUndoLastDecisionForRole(AccessRequest $request, User $user, string $role): bool
    {
        return $this->permissionChecker->canUndoLastDecisionForRole($request, $user, $role);
    }

    public function validate(AccessRequest $request, User $user, string $comment, $forcedRole = null): void
    {
        // 1. Exécution de la validation (changement de statut)
        $this->actionService->validate($request, $user, $comment, $forcedRole);

        // 2. Nettoyage et normalisation du statut et du type
        $status = mb_strtolower(trim((string) $request->getStatus()));
        $type = trim((string) $request->getType());

        // 3. Comparaison souple pour éviter les pièges de casse ('traitee', 'traitée', 'Traitee'...)
        $isTraitee = in_array($status, ['traitee', 'traitée'], true);
        $isOuverture = ($type === AccessRequest::TYPE_OUVERTURE);

        if ($isTraitee && $isOuverture) {
            // On encapsule l'appel dans un try/catch au niveau du WorkflowService pour que,
            // si le template Twig plante, l'erreur s'affiche directement à l'écran (Page blanche d'erreur)
            // au lieu de mourir silencieusement dans le code.
            try {
                $this->notificationService->sendNoteAccompagnement($request);
            } catch (\Throwable $e) {
                // Si l'envoi plante (ex: problème Twig), on force l'affichage de l'erreur
                throw new \RuntimeException(
                    "Erreur fatale lors du rendu ou de l'envoi de la note d'accompagnement : " . $e->getMessage(),
                    0,
                    $e
                );
            }
        }
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

    public function undoLastDecisionForRole(AccessRequest $request, User $user, string $role, string $comment): void
    {
        $this->actionService->undoLastDecisionForRole($request, $user, $role, $comment);
    }

    public static function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    public function canEditAfterRefusal(AccessRequest $request, User $user): bool
    {
        return $this->permissionChecker->canEditAfterRefusal($request, $user);
    }

    public function isInfoEditLocked(AccessRequest $request, User $user): bool
    {
        return $this->permissionChecker->isInfoEditLocked($request, $user);
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