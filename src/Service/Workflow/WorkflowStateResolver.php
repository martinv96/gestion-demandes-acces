<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Repository\WorkflowTransitionConfigRepository;

class WorkflowStateResolver
{
    public const DEFAULT_WORKFLOW_CODE = 'default_access';
    private const PARALLEL_FALLBACK_ROLES = ['ROLE_ST', 'ROLE_DSI'];

    /**
     * @var array<string, array<string, array{role: string, next: string}>>
     */
    private const TRANSITIONS = [
        AccessRequest::STATUS_EN_ATTENTE_RH => [
            'validate' => ['role' => 'ROLE_RH', 'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION],
            'refuse' => ['role' => 'ROLE_RH', 'next' => 'refusee_rh'],
        ],
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION => [
            'validate' => ['role' => 'ROLE_ST', 'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION],
            'refuse' => ['role' => 'ROLE_ST', 'next' => 'refusee_st'],
        ],
        AccessRequest::STATUS_EN_ATTENTE_ST => [
            'validate' => ['role' => 'ROLE_ST', 'next' => AccessRequest::STATUS_EN_ATTENTE_DSI],
            'refuse' => ['role' => 'ROLE_ST', 'next' => 'refusee_st'],
        ],
        AccessRequest::STATUS_EN_ATTENTE_DSI => [
            'validate' => ['role' => 'ROLE_DSI', 'next' => AccessRequest::STATUS_TRAITEE],
            'refuse' => ['role' => 'ROLE_DSI', 'next' => 'refusee_dsi'],
        ],
    ];

    public function __construct(
        private WorkflowTransitionConfigRepository $configRepository
    ) {}

    public function resolveTransition(AccessRequest $request, User $user, string $action): ?array
    {
        $status = (string) ($request->getStatus() ?? '');

        if ($action === 'validate' && $this->isParallelServicePhase($status)) {
            $role = $this->resolveParallelRoleForUser($request, $user);
            if ($role === null || $this->hasRoleAlreadyValidated($request, $role)) {
                return null;
            }

            return [
                'role' => $role,
                'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION,
            ];
        }

        $refusalCycleTransition = $this->resolveRefusalCycleTransition($request, $user, $action, $status);
        if ($refusalCycleTransition !== null) {
            return $refusalCycleTransition;
        }

        $snapshotTransition = $this->findTransitionInSnapshot($request, $user, $action, $status);
        if ($snapshotTransition !== null) {
            return $snapshotTransition;
        }

        $rows = $this->configRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
        foreach ($rows as $row) {
            if (
                $row->getAction() === $action
                && $row->getFromStatus() === $status
                && in_array($row->getRequiredRole(), $user->getRoles(), true)
            ) {
                return [
                    'role' => $row->getRequiredRole(),
                    'next' => $row->getToStatus(),
                ];
            }
        }

        return self::TRANSITIONS[$status][$action] ?? null;
    }

    public function isParallelServicePhase(string $status): bool
    {
        return str_starts_with($status, 'en_attente_')
            && $status !== AccessRequest::STATUS_EN_ATTENTE_RH;
    }

    /**
     * @return list<string>
     */
    public function getParallelRequiredRoles(AccessRequest $request): array
    {
        $roles = [];
        $snapshot = $request->getWorkflowSnapshot();

        if (is_array($snapshot)) {
            foreach ($snapshot as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (($row['action'] ?? null) !== 'validate') {
                    continue;
                }

                $fromStatus = (string) ($row['fromStatus'] ?? '');
                if (!str_starts_with($fromStatus, 'en_attente_')) {
                    continue;
                }

                if ($fromStatus === AccessRequest::STATUS_EN_ATTENTE_RH) {
                    continue;
                }

                $role = strtoupper(trim((string) ($row['requiredRole'] ?? '')));
                if ($role === '' || $role === 'ROLE_RH') {
                    continue;
                }

                $roles[] = $role;
            }
        }

        if ($roles === []) {
            $rows = $this->configRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
            foreach ($rows as $row) {
                if ($row->getAction() !== 'validate') {
                    continue;
                }

                $fromStatus = (string) $row->getFromStatus();
                if (!str_starts_with($fromStatus, 'en_attente_') || $fromStatus === AccessRequest::STATUS_EN_ATTENTE_RH) {
                    continue;
                }

                $role = strtoupper(trim((string) $row->getRequiredRole()));
                if ($role === '' || $role === 'ROLE_RH') {
                    continue;
                }

                $roles[] = $role;
            }
        }

        if ($roles === []) {
            $roles = self::PARALLEL_FALLBACK_ROLES;
        }

        return array_values(array_unique($roles));
    }

    public function resolveParallelRoleForUser(AccessRequest $request, User $user, array $requiredRoles = []): ?string
    {
        $roles = $requiredRoles !== [] ? $requiredRoles : $this->getParallelRequiredRoles($request);
        $userRoles = $user->getRoles();

        foreach ($roles as $requiredRole) {
            if (in_array($requiredRole, $userRoles, true)) {
                return $requiredRole;
            }
        }

        return null;
    }

    public function hasRoleAlreadyValidated(AccessRequest $request, string $role): bool
    {
        return in_array($role, $this->getValidatedParallelRoles($request, [$role]), true);
    }

    /**
     * @param list<string> $requiredRoles
     * @return list<string>
     */
    public function getValidatedParallelRoles(AccessRequest $request, array $requiredRoles): array
    {
        $doneRoles = [];

        foreach ($request->getRequestId() as $history) {
            if (!$history instanceof WorkflowHistory) {
                continue;
            }

            $oldStatus = (string) ($history->getOldStatus() ?? '');
            if (!$this->isParallelServicePhase($oldStatus)) {
                continue;
            }

            $newStatus = (string) ($history->getNewStatus() ?? '');
            if ($newStatus !== AccessRequest::STATUS_EN_ATTENTE_VALIDATION && $newStatus !== AccessRequest::STATUS_TRAITEE) {
                continue;
            }

            $comment = (string) ($history->getCommentary() ?? '');
            if (str_starts_with($comment, 'Annulation de décision')) {
                continue;
            }

            $historyUser = $history->getUser();
            if (!$historyUser instanceof User) {
                continue;
            }

            foreach ($requiredRoles as $requiredRole) {
                if (in_array($requiredRole, $historyUser->getRoles(), true)) {
                    $doneRoles[] = $requiredRole;
                }
            }
        }

        return array_values(array_unique($doneRoles));
    }

    /**
     * @param list<string> $requiredRoles
     */
    public function areAllParallelRolesValidated(AccessRequest $request, array $requiredRoles): bool
    {
        if ($requiredRoles === []) {
            return true;
        }

        $validatedRoles = $this->getValidatedParallelRoles($request, $requiredRoles);

        return array_diff($requiredRoles, $validatedRoles) === [];
    }

    public function getLatestHistory(AccessRequest $request): ?WorkflowHistory
    {
        $latestHistory = null;

        foreach ($request->getRequestId() as $history) {
            if (!$history instanceof WorkflowHistory) {
                continue;
            }

            if ($latestHistory === null || $this->isMoreRecentHistory($history, $latestHistory)) {
                $latestHistory = $history;
            }
        }

        return $latestHistory;
    }

    /**
     * @return list<string>
     */
    public function getWorkflowRoles(User $user): array
    {
        $roles = array_filter(
            $user->getRoles(),
            static fn(string $role): bool => str_starts_with($role, 'ROLE_')
                && !in_array($role, ['ROLE_USER', 'ROLE_ADMIN'], true)
        );

        return array_values(array_unique($roles));
    }

    public function shareWorkflowActor(User $currentUser, User $latestUser): bool
    {
        $currentRoles = $this->getWorkflowRoles($currentUser);
        $latestRoles = $this->getWorkflowRoles($latestUser);

        if ($currentRoles !== [] && $latestRoles !== []) {
            return array_intersect($currentRoles, $latestRoles) !== [];
        }

        return $currentUser->getUserIdentifier() !== ''
            && $currentUser->getUserIdentifier() === $latestUser->getUserIdentifier();
    }

    /**
     * @return list<string>
     */
    public function getNextValidatorRoles(AccessRequest $request): array
    {
        $status = (string) ($request->getStatus() ?? '');
        if ($status === '' || $status === AccessRequest::STATUS_TRAITEE || str_starts_with($status, 'refusee_')) {
            return [];
        }

        if ($status === AccessRequest::STATUS_EN_ATTENTE_VALIDATION) {
            $parallelRoles = $this->getParallelRequiredRoles($request);
            if ($parallelRoles === []) {
                return [];
            }

            $validatedRoles = $this->getValidatedParallelRoles($request, $parallelRoles);

            return array_values(array_diff($parallelRoles, $validatedRoles));
        }

        $roles = [];
        $snapshot = $request->getWorkflowSnapshot();
        if (is_array($snapshot)) {
            foreach ($snapshot as $row) {
                if (!is_array($row)) {
                    continue;
                }

                if (($row['action'] ?? null) !== 'validate') {
                    continue;
                }

                if ((string) ($row['fromStatus'] ?? '') !== $status) {
                    continue;
                }

                $role = strtoupper(trim((string) ($row['requiredRole'] ?? '')));
                if ($role !== '') {
                    $roles[] = $role;
                }
            }
        }

        if ($roles === []) {
            $rows = $this->configRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
            foreach ($rows as $row) {
                if ($row->getAction() !== 'validate' || (string) $row->getFromStatus() !== $status) {
                    continue;
                }

                $role = strtoupper(trim((string) $row->getRequiredRole()));
                if ($role !== '') {
                    $roles[] = $role;
                }
            }
        }

        if ($roles === [] && isset(self::TRANSITIONS[$status]['validate']['role'])) {
            $fallbackRole = strtoupper(trim((string) self::TRANSITIONS[$status]['validate']['role']));
            if ($fallbackRole !== '') {
                $roles[] = $fallbackRole;
            }
        }

        return array_values(array_unique($roles));
    }

    /**
     * @return array{role: string, next: string}|null
     */
    private function resolveRefusalCycleTransition(AccessRequest $request, User $user, string $action, string $status): ?array
    {
        if ($action === 'refuse' && $this->isParallelServicePhase($status)) {
            $requiredRole = $this->resolveParallelRoleForUser($request, $user);
            if ($requiredRole === null) {
                return null;
            }

            return [
                'role' => $requiredRole,
                'next' => sprintf('refusee_%s', $this->extractRoleCode($requiredRole)),
            ];
        }

        if ($action === 'refuse' && str_starts_with($status, 'en_attente_') && $status !== AccessRequest::STATUS_EN_ATTENTE_VALIDATION) {
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

            return [
                'role' => 'ROLE_RH',
                'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION,
            ];
        }

        return null;
    }

    /**
     * @return array{role: string, next: string}|null
     */
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

    private function extractRoleCode(string $role): string
    {
        $code = strtolower(preg_replace('/^ROLE_/', '', strtoupper($role)) ?? '');

        return $code !== '' ? $code : 'service';
    }

    private function isMoreRecentHistory(WorkflowHistory $candidate, WorkflowHistory $reference): bool
    {
        $candidateTimestamp = $candidate->getDate()?->getTimestamp() ?? 0;
        $referenceTimestamp = $reference->getDate()?->getTimestamp() ?? 0;

        if ($candidateTimestamp !== $referenceTimestamp) {
            return $candidateTimestamp > $referenceTimestamp;
        }

        return ($candidate->getId() ?? 0) > ($reference->getId() ?? 0);
    }
}