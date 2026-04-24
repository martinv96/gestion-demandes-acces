<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Repository\UserRepository;
use App\Repository\WorkflowTransitionConfigRepository;

class WorkflowBlockageHelper
{
    private const DEFAULT_WORKFLOW_CODE = 'default_access';

    /**
     * @var array<string, array<string, array{role: string, next: string}>>
     */
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
    private const STATUS_LABELS = [
        AccessRequest::STATUS_EN_ATTENTE_RH => 'En attente RH',
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION => 'En attente validations services',
        AccessRequest::STATUS_EN_ATTENTE_ST => 'En attente ST',
        AccessRequest::STATUS_EN_ATTENTE_DSI => 'En attente DSI',
        AccessRequest::STATUS_TRAITEE => 'Traitee',
        'refusee_rh' => 'Refusee par RH',
        'refusee_st' => 'Refusee par ST',
        'refusee_dsi' => 'Refusee par DSI',
    ];

    private const NEUTRAL_WAITING_STATUSES = [
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION,
    ];

    public function __construct(
        private WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
        private UserRepository $userRepository,
    ) {
    }

    public function canUnblockByRh(AccessRequest $request, User $user): bool
    {
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

        $code = substr($status, strlen('en_attente_'));
        if ($code === '') {
            return false;
        }

        $blockedRole = 'ROLE_' . strtoupper($code);

        if ($blockedRole === 'ROLE_RH') {
            return false;
        }

        if ($this->hasActiveUserForWorkflowRole($blockedRole)) {
            return false;
        }

        return $this->resolveNextValidationStatus($request, $status) !== null;
    }

    public function resolveNextValidationStatus(AccessRequest $request, string $status): ?string
    {
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

        return self::FALLBACK_TRANSITIONS[$status]['validate']['next'] ?? null;
    }

    public function isBlockedByMissingValidator(AccessRequest $request): bool
    {
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

        return !$this->hasActiveUserForWorkflowRole($blockedRole);
    }

    public function getMissingValidatorLabel(AccessRequest $request): string
    {
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
        return (bool) $this->userRepository->hasActiveUserForWorkflowRole($workflowRole);
    }
}
