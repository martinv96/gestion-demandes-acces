<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\WorkflowTransitionConfigRepository;

/**
 * Centralise la détection des demandes bloquées faute de validateur actif.
 * Il détermine aussi si RH peut relancer une demande vers l'étape suivante.
 */
class WorkflowBlockageHelper
{
    // Workflow utilisé lorsque la demande ne possède pas son propre instantané de transitions.
    private const DEFAULT_WORKFLOW_CODE = 'default_access';

    /**
     * @var array<string, array<string, array{role: string, next: string}>>
     */
    // Dernier filet de sécurité si aucune transition n'est enregistrée en base.
    private const FALLBACK_TRANSITIONS = [
        AccessRequest::STATUS_EN_ATTENTE_RH => [
            'validate' => ['role' => 'ROLE_RH', 'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION],
        ],
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION => [
            'validate' => ['role' => 'ROLE_ST', 'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION],
        ],
        AccessRequest::STATUS_EN_ATTENTE_ST => [
            'validate' => ['role' => 'ROLE_ST', 'next' => AccessRequest::STATUS_EN_ATTENTE_DSI],
        ],
        AccessRequest::STATUS_EN_ATTENTE_DSI => [
            'validate' => ['role' => 'ROLE_DSI', 'next' => AccessRequest::STATUS_TRAITEE],
        ],
    ];

    /**
     * @var array<string, string>
     */
    // Libellés destinés aux messages affichés aux utilisateurs.
    private const STATUS_LABELS = [
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

    // Ces étapes n'attendent pas un service unique et ne doivent donc jamais être bloquées ici.
    private const NEUTRAL_WAITING_STATUSES = [
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION,
        AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT,
    ];

    public function __construct(
        private WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
        private UserRepository $userRepository,
    ) {
    }

    public function canUnblockByRh(AccessRequest $request, User $user): bool
    {
        // Seul RH peut débloquer une demande après l'absence d'un validateur.
        if (!in_array('ROLE_RH', $user->getRoles(), true)) {
            return false;
        }

        $status = (string) ($request->getStatus() ?? '');
        if (in_array($status, self::NEUTRAL_WAITING_STATUSES, true)) {
            return false;
        }

        if (!str_starts_with($status, 'en_attente_')) {
            return false;
        }

        // Le statut "en_attente_dsi" devient le rôle attendu "ROLE_DSI".
        $code = substr($status, strlen('en_attente_'));
        if ($code === '') {
            return false;
        }

        $blockedRole = 'ROLE_' . strtoupper($code);

        if ($blockedRole === 'ROLE_RH') {
            return false;
        }

        // Il n'y a rien à débloquer dès qu'un utilisateur actif existe pour ce rôle.
        if ($this->hasActiveUserForWorkflowRole($blockedRole)) {
            return false;
        }

        return $this->resolveNextValidationStatus($request, $status) !== null;
    }

    public function resolveNextValidationStatus(AccessRequest $request, string $status): ?string
    {
        // Priorité 1 : l'instantané conserve le workflow applicable lors de la création de la demande.
        $snapshot = $request->getWorkflowSnapshot();
        if (is_array($snapshot)) {
            foreach ($snapshot as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (($row['action'] ?? '') !== 'validate') {
                    continue;
                }

                if (($row['fromStatus'] ?? '') !== $status) {
                    continue;
                }

                $next = (string) ($row['toStatus'] ?? '');
                if ($next !== '') {
                    return $next;
                }
            }
        }

        // Priorité 2 : la configuration actuellement active du workflow par défaut.
        $rows = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
        foreach ($rows as $row) {
            if ($row->getAction() !== 'validate' || $row->getFromStatus() !== $status) {
                continue;
            }

            $next = (string) ($row->getToStatus() ?? '');
            if ($next !== '') {
                return $next;
            }
        }

        // Priorité 3 : transitions codées en dur pour les anciennes demandes ou une configuration incomplète.
        return self::FALLBACK_TRANSITIONS[$status]['validate']['next'] ?? null;
    }

    public function isBlockedByMissingValidator(AccessRequest $request): bool
    {
        // Une demande est bloquée uniquement lorsqu'elle attend un rôle de service précis.
        $status = (string) ($request->getStatus() ?? '');
        if (in_array($status, self::NEUTRAL_WAITING_STATUSES, true)) {
            return false;
        }

        if (!str_starts_with($status, 'en_attente_')) {
            return false;
        }

        $code = substr($status, strlen('en_attente_'));
        if ($code === '') {
            return false;
        }

        $blockedRole = 'ROLE_' . strtoupper($code);

        // Le blocage disparaît automatiquement dès qu'un compte actif rejoint le service concerné.
        return !$this->hasActiveUserForWorkflowRole($blockedRole);
    }

    public function getMissingValidatorLabel(AccessRequest $request): string
    {
        // Produit un libellé lisible pour l'alerte affichée dans le détail de la demande.
        $status = (string) ($request->getStatus() ?? '');

        if (isset(self::STATUS_LABELS[$status]) && str_starts_with(self::STATUS_LABELS[$status], 'En attente ')) {
            return trim(str_replace('En attente ', '', self::STATUS_LABELS[$status]));
        }

        if (str_starts_with($status, 'en_attente_')) {
            $code = strtoupper((string) substr($status, strlen('en_attente_')));
            if ($code !== '') {
                return $code;
            }
        }

        return 'le service validateur';
    }

    private function hasActiveUserForWorkflowRole(string $workflowRole): bool
    {
        // Le repository fait la correspondance entre le rôle de workflow et le code du service utilisateur.
        return (bool) $this->userRepository->hasActiveUserForWorkflowRole($workflowRole);
    }
}
