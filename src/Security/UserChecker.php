<?php

namespace App\Security;
use App\Entity\User;

use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;
use Symfony\Component\Security\Core\User\UserCheckerInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;

class UserChecker implements UserCheckerInterface
{
    public function checkPreAuth(UserInterface $user): void
    {
        // Vérifie si l'utilisateur est actif
        if (!$user instanceof User) {
            return;
        }

        if ($user->isActive() !== true) {
            throw new CustomUserMessageAccountStatusException('Votre compte est désactivé. Veuillez contacter l\'administrateur.');
        }
    }

    public function checkPostAuth(UserInterface $user, ?TokenInterface $token = null): void
    {
        // Pas de vérifications supplémentaires après l'authentification
    }

    
}