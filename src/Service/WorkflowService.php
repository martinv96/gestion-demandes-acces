<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Repository\UserRepository;
use App\Repository\WorkflowTransitionConfigRepository;
use App\Service\Workflow\WorkflowBlockageHelper;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;
use LogicException;


class WorkflowService
{
    private WorkflowBlockageHelper $workflowBlockageHelper;
    private mixed $userRepository;
    private const PARALLEL_FALLBACK_ROLES = ['ROLE_ST', 'ROLE_DSI'];

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
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION => 'En attente validations services',
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
            'validate' => ['role' => 'ROLE_RH',  'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION],
            'refuse'   => ['role' => 'ROLE_RH',  'next' => self::STATUS_REFUSEE_RH],
        ],
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION => [
            'validate' => ['role' => 'ROLE_ST',  'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION],
            'refuse'   => ['role' => 'ROLE_ST',  'next' => self::STATUS_REFUSEE_ST],
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

    private function resolveRefusalCycleTransition(AccessRequest $request, User $user, string $action, string $status): ?array
    {
        if ($action === 'refuse' && $this->isParallelServicePhaseStatus($status)) {
            $requiredRole = $this->resolveParallelValidationRoleForUser($request, $user);
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
    public function __construct(
        private EntityManagerInterface $em,
        private WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
        private MailerInterface $mailer,
        ?UserRepository $userRepository = null,
        private string $mailerFrom = 'no-reply@localhost',
        private ?LoggerInterface $logger = null,
    ) {
        if ($userRepository instanceof UserRepository) {
            $this->userRepository = $userRepository;
        } else {
            $this->userRepository = $this->em->getRepository(User::class);
        }

        $this->workflowBlockageHelper = new WorkflowBlockageHelper(
            $this->workflowTransitionConfigRepository,
            $this->userRepository,
            self::DEFAULT_WORKFLOW_CODE,
            self::TRANSITIONS,
            self::LABELS,
        );
    }

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

    public function canUndoLastDecision(AccessRequest $request, User $user): bool
    {
        $latestHistory = $this->getLatestHistory($request);
        if (!$latestHistory instanceof WorkflowHistory) {
            return false;
        }

        $currentStatus = $request->getStatus() ?? '';
        $latestNewStatus = $latestHistory->getNewStatus() ?? '';

        if ($currentStatus === '' || $latestNewStatus === '' || $currentStatus !== $latestNewStatus) {
            return false;
        }

        if ($this->isClosureRequest($request)) {
            return $currentStatus === AccessRequest::STATUS_TRAITEE;
        }

        $latestUser = $latestHistory->getUser();
        if (!$latestUser instanceof User) {
            return false;
        }

        return $this->shareWorkflowActor($user, $latestUser);
    }

    // Méthode pour valider une demande avec un commentaire obligatoire
    public function validate(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        if ($this->isParallelServicePhaseStatus((string) ($request->getStatus() ?? ''))) {
            $this->applyParallelValidation($request, $user, $comment);

            return;
        }

        $transition = $this->resolveTransition($request, $user, 'validate');

        if ($transition === null || !in_array($transition['role'], $user->getRoles(), true)) {
            throw new \LogicException('Transition de validation non autorisée pour ce rôle ou ce statut.');
        }

        $this->applyTransition($request, $user, $transition['next'], $comment);
    }

    public function canFinalizeClosureByAnyUser(AccessRequest $request): bool
    {
        if (!$this->isClosureRequest($request)) {
            return false;
        }

        if (!$this->isParallelServicePhaseStatus((string) ($request->getStatus() ?? ''))) {
            return false;
        }

        $requiredRoles = $this->getParallelValidationRoles($request);
        if ($requiredRoles !== [] && !$this->areAllParallelRolesValidated($request, $requiredRoles)) {
            return false;
        }

        return !$this->hasPendingMaterialReturns($request);
    }

    public function finalizeClosureByAnyUser(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        if (!$this->canFinalizeClosureByAnyUser($request)) {
            throw new \LogicException('Cette demande de fermeture ne peut pas être validée pour le moment.');
        }

        $this->applyTransition($request, $user, AccessRequest::STATUS_TRAITEE, $comment);
    }

    private function isClosureRequest(AccessRequest $request): bool
    {
        try {
            return $request->getType() === AccessRequest::TYPE_FERMETURE;
        } catch (\Error) {
            return false;
        }
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

    public function undoLastDecision(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire pour annuler une décision.');
        }

        $latestHistory = $this->getLatestHistory($request);
        if (!$latestHistory instanceof WorkflowHistory) {
            throw new \LogicException('Aucune décision récente à annuler.');
        }

        if (!$this->canUndoLastDecision($request, $user)) {
            throw new \LogicException('Annulation de décision non autorisée.');
        }

        $previousStatus = trim((string) $latestHistory->getOldStatus());
        if ($previousStatus === '') {
            throw new \LogicException('Statut précédent introuvable.');
        }

        $this->applyTransition($request, $user, $previousStatus, 'Annulation de décision : ' . trim($comment));
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

        $this->notifyAllActors($request, $comment);
    }

    private function resolveTransition(AccessRequest $request, User $user, string $action): ?array
    {
        $status = $request->getStatus() ?? '';

        if ($action === 'validate' && $this->isParallelServicePhaseStatus($status)) {
            $role = $this->resolveParallelValidationRoleForUser($request, $user);
            if ($role === null || $this->hasUserAlreadyValidatedParallelStep($request, $role)) {
                return null;
            }

            return [
                'role' => $role,
                'next' => AccessRequest::STATUS_EN_ATTENTE_VALIDATION,
            ];
        }

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

    private function hasPendingMaterialReturns(AccessRequest $request): bool
    {
        foreach ($request->getRessources() as $ressource) {
            if ($ressource->getCategory() === 'materiel') {
                return true;
            }
        }
        return false;
    }


    // Méthode pour vérifier si un utilisateur peut éditer une demande après un refus
    public function canEditAfterRefusal(AccessRequest $request, User $user): bool
    {
        $status = $request->getStatus() ?? '';

        if (str_starts_with($status, 'refusee_')) {
            return in_array('ROLE_RH', $user->getRoles(), true);
        }

        if ($this->isParallelServicePhaseStatus($status)) {
            return $this->resolveParallelValidationRoleForUser($request, $user) !== null;
        }

        return false;
    }

    private function applyParallelValidation(AccessRequest $request, User $user, string $comment): void
    {
        $requiredRoles = $this->getParallelValidationRoles($request);
        $actorRole = $this->resolveParallelValidationRoleForUser($request, $user, $requiredRoles);

        if ($actorRole === null) {
            throw new \LogicException('Transition de validation non autorisée pour ce rôle ou ce statut.');
        }

        if ($this->hasUserAlreadyValidatedParallelStep($request, $actorRole)) {
            throw new \LogicException('Ce service a déjà validé cette demande.');
        }

        $validatedRoles = $this->getValidatedParallelRoles($request, $requiredRoles);
        $validatedRoles[] = $actorRole;
        $validatedRoles = array_values(array_unique($validatedRoles));

        $allValidated = $requiredRoles === []
            || array_diff($requiredRoles, $validatedRoles) === [];

        $nextStatus = $allValidated ? AccessRequest::STATUS_TRAITEE : AccessRequest::STATUS_EN_ATTENTE_VALIDATION;

        if (
            $allValidated
            && $this->isClosureRequest($request)
            && $this->hasPendingMaterialReturns($request)
        ) {
            $nextStatus = AccessRequest::STATUS_EN_ATTENTE_VALIDATION;
        }

        $this->applyTransition($request, $user, $nextStatus, $comment);
    }

    /**
     * @return list<string>
     */
    private function getParallelValidationRoles(AccessRequest $request): array
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
            $rows = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
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

    private function resolveParallelValidationRoleForUser(AccessRequest $request, User $user, array $requiredRoles = []): ?string
    {
        $roles = $requiredRoles !== [] ? $requiredRoles : $this->getParallelValidationRoles($request);
        $userRoles = $user->getRoles();

        foreach ($roles as $requiredRole) {
            if (in_array($requiredRole, $userRoles, true)) {
                return $requiredRole;
            }
        }

        return null;
    }

    /**
     * @param list<string> $requiredRoles
     * @return list<string>
     */
    private function getValidatedParallelRoles(AccessRequest $request, array $requiredRoles): array
    {
        $doneRoles = [];

        foreach ($request->getRequestId() as $history) {
            if (!$history instanceof WorkflowHistory) {
                continue;
            }

            $oldStatus = (string) ($history->getOldStatus() ?? '');
            if (!$this->isParallelServicePhaseStatus($oldStatus)) {
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

    private function hasUserAlreadyValidatedParallelStep(AccessRequest $request, string $actorRole): bool
    {
        $validatedRoles = $this->getValidatedParallelRoles($request, [$actorRole]);

        return in_array($actorRole, $validatedRoles, true);
    }

    /**
     * @param list<string> $requiredRoles
     */
    private function areAllParallelRolesValidated(AccessRequest $request, array $requiredRoles): bool
    {
        if ($requiredRoles === []) {
            return true;
        }

        $validatedRoles = $this->getValidatedParallelRoles($request, $requiredRoles);

        return array_diff($requiredRoles, $validatedRoles) === [];
    }

    private function extractRoleCode(string $role): string
    {
        $code = strtolower(preg_replace('/^ROLE_/', '', strtoupper($role)) ?? '');

        return $code !== '' ? $code : 'service';
    }

    private function isParallelServicePhaseStatus(string $status): bool
    {
        return str_starts_with($status, 'en_attente_')
            && $status !== AccessRequest::STATUS_EN_ATTENTE_RH;
    }

    private function getLatestHistory(AccessRequest $request): ?WorkflowHistory
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

    private function isMoreRecentHistory(WorkflowHistory $candidate, WorkflowHistory $reference): bool
    {
        $candidateTimestamp = $candidate->getDate()?->getTimestamp() ?? 0;
        $referenceTimestamp = $reference->getDate()?->getTimestamp() ?? 0;

        if ($candidateTimestamp !== $referenceTimestamp) {
            return $candidateTimestamp > $referenceTimestamp;
        }

        return ($candidate->getId() ?? 0) > ($reference->getId() ?? 0);
    }

    /**
     * @return list<string>
     */
    private function getWorkflowRoles(User $user): array
    {
        $roles = array_filter(
            $user->getRoles(),
            static fn(string $role): bool => str_starts_with($role, 'ROLE_') && !in_array($role, ['ROLE_USER', 'ROLE_ADMIN'], true)
        );

        return array_values(array_unique($roles));
    }

    private function shareWorkflowActor(User $currentUser, User $latestUser): bool
    {
        $currentRoles = $this->getWorkflowRoles($currentUser);
        $latestRoles = $this->getWorkflowRoles($latestUser);

        if ($currentRoles !== [] && $latestRoles !== []) {
            return array_intersect($currentRoles, $latestRoles) !== [];
        }

        return $currentUser->getUserIdentifier() !== ''
            && $currentUser->getUserIdentifier() === $latestUser->getUserIdentifier();
    }


    public function canUnblockByRh(AccessRequest $request, User $user): bool
    {
        return $this->workflowBlockageHelper->canUnblockByRh($request, $user);
    }

    public function unblockByRh(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire pour débloquer.');
        }

        if (!$this->canUnblockByRh($request, $user)) {
            throw new LogicException('Deblocage non autorisé pour cette demande.');
        }

        $currentStatus = (string) ($request->getStatus() ?? '');
        $nextStatus = $this->workflowBlockageHelper->resolveNextValidationStatus($request, $currentStatus);

        if ($nextStatus === null || $nextStatus === '') {
            throw new \LogicException('Transition de déblocage introuvable.');
        }

        $this->applyTransition(
            $request,
            $user,
            $nextStatus,
            'Deblocage RH (compte valideur inactif/supprime) : ' . trim($comment)
        );
    }

    public function isBlockedByMissingValidator(AccessRequest $request): bool
    {
        return $this->workflowBlockageHelper->isBlockedByMissingValidator($request);
    }

    public function getMissingValidatorLabel(AccessRequest $request): string
    {
        return $this->workflowBlockageHelper->getMissingValidatorLabel($request);
    }

    /** 
     * Pour notifier tout le monde
     */

    public function notifyAllActors(AccessRequest $request, string $comment): void
    {
        if (!is_object($this->userRepository) || !method_exists($this->userRepository, 'findBy')) {
            return;
        }

        try {
            $allUsers = $this->userRepository->findBy(['isActive' => true]);
        } catch (\Throwable) {
            return;
        }

        if (!is_array($allUsers) || $allUsers === []) {
            return;
        }

        $nextRoles = $this->getNextValidatorRoles($request);

        foreach ($allUsers as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $email = trim((string) ($user->getEmail() ?? ''));
            if ($email === '') {
                continue;
            }

            $isNextValidator = $nextRoles !== [] && array_intersect($nextRoles, $user->getRoles()) !== [];

            try {
                $this->sendTemplateEmail($email, $request, $comment, $isNextValidator);

                if ($this->logger instanceof LoggerInterface) {
                    $this->logger->info('Notification workflow envoyee.', [
                        'request_id' => $request->getId(),
                        'to' => $email,
                        'type' => $isNextValidator ? 'ACTION' : 'INFO',
                        'status' => $request->getStatus(),
                        'from' => $this->resolveMailerFrom(),
                    ]);
                }
            } catch (\Throwable $e) {
                if ($this->logger instanceof LoggerInterface) {
                    $this->logger->error('Echec envoi notification mail workflow.', [
                        'request_id' => $request->getId(),
                        'to' => $email,
                        'is_action' => $isNextValidator,
                        'status' => $request->getStatus(),
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    private function getNextValidatorRoles(AccessRequest $request): array
    {
        $status = (string) ($request->getStatus() ?? '');
        if ($status === '' || $status === AccessRequest::STATUS_TRAITEE || str_starts_with($status, 'refusee_')) {
            return [];
        }

        if ($status === AccessRequest::STATUS_EN_ATTENTE_VALIDATION) {
            $parallelRoles = $this->getParallelValidationRoles($request);
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
            $rows = $this->workflowTransitionConfigRepository->findActiveTransitionsForWorkflow(self::DEFAULT_WORKFLOW_CODE);
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

    private function sendTemplateEmail(string $to, AccessRequest $request, string $comment, bool $isAction): void
    {
        $subject = $isAction ? "ACTION REQUISE" : "INFO";
        $template = $isAction ? 'emails/notification_action.html.twig' : 'emails/notification_info.html.twig';
        $from = $this->resolveMailerFrom();

        $email = (new TemplatedEmail())
            ->from($from)
            ->to($to)
            ->subject(sprintf('%s : Demande #%d (%s)', $subject, $request->getId(), self::getLabel($request->getStatus())))
            ->htmlTemplate($template)
            ->context([
                'request' => $request,
                'status_label' => self::getLabel($request->getStatus()),
                'last_comment' => $comment,
            ]);

        $this->mailer->send($email);
    }

    private function resolveMailerFrom(): string
    {
        $runtimeFrom = trim((string) ($_SERVER['MAILER_FROM'] ?? $_ENV['MAILER_FROM'] ?? ''));
        if ($runtimeFrom !== '') {
            return $runtimeFrom;
        }

        $configuredFrom = trim((string) $this->mailerFrom);

        return $configuredFrom !== '' ? $configuredFrom : 'no-reply@localhost';
    }
    
    
}


