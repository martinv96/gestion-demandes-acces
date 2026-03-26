<?php

namespace App\Security\Voter;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Service\WorkflowService;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

// Voter pour gérer les permissions liées aux demandes d'accès (validation, refus, édition après refus).
// un voter est une classe qui décide si un utilisateur a le droit d'effectuer une action sur un objet donné.
final class RequestVoter extends Voter
{
    public const VALIDATE = 'REQUEST_VALIDATE';
    public const REFUSE = 'REQUEST_REFUSE';
    public const EDIT_INFO = 'REQUEST_EDIT_INFO';

    public function __construct(private WorkflowService $workflowService)
    {
    }

    protected function supports(string $attribute, mixed $subject): bool
    {
        if (!$subject instanceof AccessRequest) {
            return false;
        }

        return in_array($attribute, [
            self::VALIDATE,
            self::REFUSE,
            self::EDIT_INFO,
        ], true);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();

        if (!$user instanceof User) {
            return false;
        }

        /** @var AccessRequest $request */
        $request = $subject;

        return match ($attribute) {
            self::VALIDATE => $this->workflowService->canValidate($request, $user),
            self::REFUSE => $this->workflowService->canRefuse($request, $user),
            self::EDIT_INFO => $this->workflowService->canEditAfterRefusal($request, $user),
            default => false,
        };
    }
}