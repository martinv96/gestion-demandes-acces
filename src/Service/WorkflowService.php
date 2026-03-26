<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Entity\WorkflowHistory;
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
            'refuse'   => ['role' => 'ROLE_ST',  'next' => self::STATUS_EN_ATTENTE_RH],
        ],
        self::STATUS_EN_ATTENTE_DSI => [
            'validate' => ['role' => 'ROLE_DSI', 'next' => self::STATUS_TRAITEE],
            'refuse'   => ['role' => 'ROLE_DSI', 'next' => self::STATUS_EN_ATTENTE_ST],
        ],
    ];

    // Constructeur pour injecter l'EntityManager
    public function __construct(private EntityManagerInterface $em) {}

    public function canValidate(AccessRequest $request, User $user): bool
    {
        $transition = self::TRANSITIONS[$request->getStatus() ?? '']['validate'] ?? null;

        return $transition !== null && in_array($transition['role'], $user->getRoles(), true);
    }

    // Méthode pour vérifier si l'utilisateur peut refuser la demande
    public function canRefuse(AccessRequest $request, User $user): bool
    {
        $transition = self::TRANSITIONS[$request->getStatus() ?? '']['refuse'] ?? null;

        return $transition !== null && in_array($transition['role'], $user->getRoles(), true);
    }

    // Méthode pour valider une demande avec un commentaire obligatoire
    public function validate(AccessRequest $request, User $user, string $comment): void
    {
        if (trim($comment) === '') {
            throw new \InvalidArgumentException('Un commentaire est obligatoire en cas de validation.');
        }

        $transition = self::TRANSITIONS[$request->getStatus() ?? '']['validate'] ?? null;

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

        $transition = self::TRANSITIONS[$request->getStatus() ?? '']['refuse'] ?? null;

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

    // Méthode pour vérifier si un utilisateur peut éditer une demande après un refus
    public function canEditAfterRefusal(AccessRequest $request, User $user): bool
    {
        if (!in_array('ROLE_RH', $user->getRoles(), true)) {
            return false;
        }

        $status = $request->getStatus() ?? '';

        // RH peut éditer si la demande est refusée par RH
        if ($status === self::STATUS_REFUSEE_RH) {
            return true;
        }

        // RH ne peut pas éditer si demande traitée ou refusée par ST/DSI
        if (in_array($status, [
            self::STATUS_TRAITEE,
            self::STATUS_REFUSEE_ST,
            self::STATUS_REFUSEE_DSI,
        ], true)) {
            return false;
        }

        // RH peut éditer si en attente RH après refus de ST ou DSI
        foreach ($request->getRequestId() as $historyEntry) {
            $oldStatus = $historyEntry->getOldStatus() ?? '';
            $newStatus = $historyEntry->getNewStatus() ?? '';

            $isRefusalFromSt = $oldStatus === self::STATUS_EN_ATTENTE_ST
                && $newStatus === self::STATUS_EN_ATTENTE_RH;
            $isRefusalFromDsi = $oldStatus === self::STATUS_EN_ATTENTE_DSI
                && $newStatus === self::STATUS_EN_ATTENTE_ST;

            if ($isRefusalFromSt || $isRefusalFromDsi) {
                return true;
            }
        }

        return false;
    }
}
