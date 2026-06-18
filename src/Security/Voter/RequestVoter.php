<?php

namespace App\Security\Voter;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Service\WorkflowService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;
use Symfony\Component\Security\Core\Authorization\Voter\Vote;

// Voter pour gérer les permissions liées aux demandes d'accès (validation, refus, édition après refus).
// un voter est une classe qui décide si un utilisateur a le droit d'effectuer une action sur un objet donné.
final class RequestVoter extends Voter
{
    public const VALIDATE = 'REQUEST_VALIDATE';
    public const REFUSE = 'REQUEST_REFUSE';
    public const EDIT_INFO = 'REQUEST_EDIT_INFO';
    public const UNDO = 'REQUEST_UNDO';
    public const UNBLOCK = 'REQUEST_UNBLOCK';
    public const REMIND_SERVICE = 'REQUEST_REMIND_SERVICE';

    public function __construct(private WorkflowService $workflowService)
    {
    }

    // ! cette methode vérifie que le voter doit s'appliquer à l'attribut et au sujet donnés
    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!$subject instanceof AccessRequest) {
            return false;
        }

        return in_array($attribute, [
            self::VALIDATE,
            self::REFUSE,
            self::EDIT_INFO,
            self::UNDO,
            self::UNBLOCK,
            self::REMIND_SERVICE,
        ], true);
    }

    // ! methode qui permet de décider si l'utilisateur a le droit d'effectuer l'action sur le sujet
    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token, ?Vote $vote = null): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var AccessRequest $request */
        $request = $subject;

        // ! en fonction, on redirige vers la methode qui correspond
        return match ($attribute) {
            self::VALIDATE => $this->workflowService->canValidate($request, $user),
            self::REFUSE => $this->workflowService->canRefuse($request, $user),
            self::EDIT_INFO => $this->workflowService->canEditAfterRefusal($request, $user),
            self::UNDO => $this->workflowService->canUndoLastDecision($request, $user),
            self::UNBLOCK => $this->workflowService->canUnblockByRh($request, $user),
            self::REMIND_SERVICE => in_array('ROLE_ADMIN', $user->getRoles(), true),
            default => false,
        };
    }
}