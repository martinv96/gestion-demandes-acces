<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Repository\WorkflowTransitionConfigRepository;
use Doctrine\ORM\EntityManagerInterface;


class WorkflowService
{
    // Statuts possibles
    public const STATUS_EN_ATTENTE_RH  = 'en_attente_rh';
    public const STATUS_EN_ATTENTE_ST  = 'en_attente_st';
    public const STATUS_EN_ATTENTE_DSI = 'en_attente_dsi';
    public const STATUS_TRAITEE        = 'traitee';
    public const STATUS_REFUSEE_RH     = 'refusee_rh';
    public const STATUS_REFUSEE_ST     = 'refusee_st';
    public const STATUS_REFUSEE_DSI    = 'refusee_dsi';



    // Libellés lisibles pour l'affichage
    public const LABELS = [
        self::STATUS_EN_ATTENTE_RH  => 'En attente RH',
        self::STATUS_EN_ATTENTE_ST  => 'En attente DGA-ST',
        self::STATUS_EN_ATTENTE_DSI => 'En attente DSI',
        self::STATUS_TRAITEE        => 'Traitée',
        self::STATUS_REFUSEE_RH     => 'Refusée par RH',
        self::STATUS_REFUSEE_ST     => 'Refusée par DGA-ST',
        self::STATUS_REFUSEE_DSI    => 'Refusée par DSI',
    ];

    // Transitions: statut_actuel -> action -> [role requis, prochain statut]
    private const TRANSITIONS = [
        self::STATUS_EN_ATTENTE_RH => [
            'validate' => ['role' => 'ROLE_RH',  'next' => self::STATUS_EN_ATTENTE_ST],
            'refuse'   => ['role' => 'ROLE_RH',  'next' => self::STATUS_REFUSEE_RH],
        ],
        self::STATUS_EN_ATTENTE_ST => [
            'validate' => ['role' => 'ROLE_ST',  'next' => self::STATUS_EN_ATTENTE_DSI],
            'refuse'   => ['role' => 'ROLE_ST',  'next' => self::STATUS_REFUSEE_ST],
        ],
        self::STATUS_EN_ATTENTE_DSI => [
            'validate' => ['role' => 'ROLE_DSI', 'next' => self::STATUS_TRAITEE],
            'refuse'   => ['role' => 'ROLE_DSI', 'next' => self::STATUS_REFUSEE_DSI],
        ],
    ];

    private function resolveRhResumeNextStatus(AccessRequest $request, User $user): ?string
    {
        $snapshotTransition = $this->findTransitionInSnapshot($request, $user, 'validate', self::STATUS_EN_ATTENTE_RH);
        if ($snapshotTransition !== null) {
            return (string) $snapshotTransition['next'];
        }

        $rows = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
        foreach ($rows as $row) {
            if (
                $row->getAction() === 'validate'
                && $row->getFromStatus() === self::STATUS_EN_ATTENTE_RH
                && in_array($row->getRequiredRole(), $user->getRoles(), true)
            ) {
                return $row->getToStatus();
            }
        }

        return self::TRANSITIONS[self::STATUS_EN_ATTENTE_RH]['validate']['next'] ?? null;
    }

    private function resolveRefusalCycleTransition(AccessRequest $request, User $user, string $action, string $status): ?array
    {
        if ($action === 'refuse' && str_starts_with($status, 'en_attente_')) {
            $code = substr($status, strlen('en_attente_'));
            if ($code === '') {
                return null;
            }

            $requiredRole = sprintf('ROLE_%s', strtoupper($code));
            if (!in_array($requiredRole, $user->getRoles(), true)) {
                return null;
            }

            return [
                'role' => $requiredRole,
                'next' => sprintf('refusee_%s', $code),
            ];
        }

        if ($action === 'validate' && str_starts_with($status, 'refusee_')) {
            if (!in_array('ROLE_RH', $user->getRoles(), true)) {
                return null;
            }

            $code = substr($status, strlen('refusee_'));
            if ($code === '') {
                return null;
            }

            if ($code === 'rh') {
                $next = $this->resolveRhResumeNextStatus($request, $user);
                if ($next === null || $next === '') {
                    return null;
                }

                return [
                    'role' => 'ROLE_RH',
                    'next' => $next,
                ];
            }

            return [
                'role' => 'ROLE_RH',
                'next' => sprintf('en_attente_%s', $code),
            ];
        }

        return null;
    }

    private function findTransitionInSnapshot(AccessRequest $request, User $user, string $action, string $status): ?array
    {
        $snapshot = $request->getWorkflowSnapshot();
        if (!is_array($snapshot) || $snapshot === []) {
            return null;
        }

        foreach ($snapshot as $row) {
            if (!is_array($row)) {
                continue;
            }

            $rowAction = (string) ($row['action'] ?? '');
            $rowFromStatus = (string) ($row['fromStatus'] ?? '');
            $rowRole = (string) ($row['requiredRole'] ?? '');
            $rowToStatus = (string) ($row['toStatus'] ?? '');

            if ($rowAction !== $action || $rowFromStatus !== $status) {
                continue;
            }

            if (!in_array($rowRole, $user->getRoles(), true)) {
                continue;
            }

            if ($rowToStatus === '') {
                continue;
            }

            return [
                'role' => $rowRole,
                'next' => $rowToStatus,
            ];
        }

        return null;
    }

    private const DEFAULT_WORKFLOW_CODE = 'default_access';

    // Constructeur pour injecter l'EntityManager et le repository
    public function __construct(private EntityManagerInterface $em, private WorkflowTransitionConfigRepository $workflowTransitionConfigRepository) {}

    public function canValidate(AccessRequest $request, User $user): bool
    {
        $transition = $this->resolveTransition($request, $user, 'validate');

        return $transition !== null && in_array($transition['role'], $user->getRoles(), true);
    }

    // Méthode pour vérifier si l'utilisateur peut refuser la demande
    public function canRefuse(AccessRequest $request, User $user): bool
    {
        $transition = $this->resolveTransition($request, $user, 'refuse');

        return $transition !== null && in_array($transition['role'], $user->getRoles(), true);
    }

    // Méthode pour valider une demande avec un commentaire obligatoire
    public function validate(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        $transition = $this->resolveTransition($request, $user, 'validate');

        if ($transition === null || !in_array($transition['role'], $user->getRoles(), true)) {
            throw new \LogicException('Transition de validation non autorisée pour ce rôle ou ce statut.');
        }

        $this->applyTransition($request, $user, $transition['next'], $comment);
    }

    // Méthode pour refuser une demande avec un commentaire obligatoire
    public function refuse(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de refus.');
        }

        $transition = $this->resolveTransition($request, $user, 'refuse');

        if ($transition === null || !in_array($transition['role'], $user->getRoles(), true)) {
            throw new \LogicException('Transition de refus non autorisée pour ce rôle ou ce statut.');
        }

        $this->applyTransition($request, $user, $transition['next'], $comment);
    }

    // Méthode pour obtenir le label lisible d'un statut
    public static function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    // Méthode interne pour appliquer une transition et enregistrer l'historique
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
    }

    private function resolveTransition(AccessRequest $request, User $user, string $action): ?array
    {
        $status = $request->getStatus() ?? '';

        // Règle métier prioritaire: en cas de refus, reprise RH puis retour direct à l'étape qui a refusé.
        $refusalCycleTransition = $this->resolveRefusalCycleTransition($request, $user, $action, $status);
        if ($refusalCycleTransition !== null) {
            return $refusalCycleTransition;
        }

        // priorité au snapshot de la demande

        $snapshotTransitions = $this->findTransitionInSnapshot($request, $user, $action, $status);
        if ($snapshotTransitions !== null) {
            return $snapshotTransitions;
        }

        //fallback config active en base
        $rows = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
        foreach ($rows as $row) {
            if ($row->getAction() === $action && $row->getFromStatus() === $status && in_array($row->getRequiredRole(), $user->getRoles(), true)) {
                return [
                    'role' => $row->getRequiredRole(),
                    'next' => $row->getToStatus(),
                ];
            }
        }

        // fallback en dur
        return self::TRANSITIONS[$status][$action] ?? null;
    }


    // Méthode pour vérifier si un utilisateur peut éditer une demande après un refus
    public function canEditAfterRefusal(AccessRequest $request, User $user): bool
    {
        if (!in_array('ROLE_RH', $user->getRoles(), true)) {
            return false;
        }

        $status = $request->getStatus() ?? '';

        return str_starts_with($status, 'refusee_');
    }
}
