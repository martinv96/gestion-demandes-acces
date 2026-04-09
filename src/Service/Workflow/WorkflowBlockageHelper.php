<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Repository\WorkflowTransitionConfigRepository;

class WorkflowBlockageHelper
{
    private const NEUTRAL_WAITING_STATUSES = [
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION,
    ];

    /**
     * @param array<string, array<string, array{role: string, next: string}>> $fallbackTransitions
     * @param array<string, string> $statusLabels
     */
    public function __construct(
        private WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
        private mixed $userRepository,
        private string $defaultWorkflowCode,
        private array $fallbackTransitions,
        private array $statusLabels,
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

        $rows = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow($this->defaultWorkflowCode);
        foreach ($rows as $row) {
            if ($row->getAction() !== 'validate' || $row->getFromStatus() !== $status) {
                continue;
            }

            $next = (string) ($row->getToStatus() ?? '');
            if ($next !== '') {
                return $next;
            }
        }

        return $this->fallbackTransitions[$status]['validate']['next'] ?? null;
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

        if (isset($this->statusLabels[$status]) && str_starts_with($this->statusLabels[$status], 'En attente ')) {
            return trim(str_replace('En attente ', '', $this->statusLabels[$status]));
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
        if (!is_object($this->userRepository) || !method_exists($this->userRepository, 'hasActiveUserForWorkflowRole')) {
            return true;
        }

        return (bool) $this->userRepository->hasActiveUserForWorkflowRole($workflowRole);
    }
}
