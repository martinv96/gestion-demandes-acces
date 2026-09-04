<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\LogicException;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

/**
 * Gère les pages de connexion et le changement obligatoire de mot de passe.
 * L'authentification elle-même est configurée dans config/packages/security.yaml.
 */
final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        // Un utilisateur déjà connecté ne doit pas revenir sur le formulaire de connexion.
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Symfony fournit le dernier identifiant saisi et l'erreur éventuelle du firewall.
        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): void
    {
        // Cette méthode ne s'exécute jamais : le firewall intercepte la route avant le contrôleur.
        throw new LogicException('Cette méthode est interceptée par le firewall logout.');
    }

    #[Route('/change-password', name: 'app_force_change_password', methods: ['GET', 'POST'])]
    public function forceChangePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        // Cette page est accessible uniquement à une session utilisateur authentifiée.
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        // Empêche l'accès direct à cette page quand le mot de passe a déjà été changé.
        if (!$user->isMustChangePassword()) {
            return $this->redirectToRoute('app_dashboard');
        }

        // Les vérifications suivantes sont faites seulement à l'envoi du formulaire.
        if ($request->isMethod('POST')) {
            // Le jeton protège cette action sensible contre une soumission depuis un autre site.
            if (!$this->isCsrfTokenValid('force_change_password', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de sécurité invalide.');
                return $this->redirectToRoute('app_force_change_password');
            }

            $newPassword     = (string) $request->request->get('new_password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            // Politique minimale : 12 caractères, minuscule, majuscule, chiffre et caractère spécial.
            if (
                strlen($newPassword) < 12 || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/',$newPassword) || !preg_match('/\d/', $newPassword) || !preg_match('/[\W_]/',$newPassword)
                ) {
                $this->addFlash('danger', 'Le mot de passe doit contenir au moins 12 caractères, une minuscule, un chiffre et un caractère spécial.');
                return $this->redirectToRoute('app_force_change_password');
            }

            // La confirmation évite d'enregistrer une faute de frappe dans le nouveau mot de passe.
            if ($newPassword !== $confirmPassword) {
                $this->addFlash('danger', 'Les mots de passe ne correspondent pas.');
                return $this->redirectToRoute('app_force_change_password');
            }

            // Seul le hash est enregistré en base ; le mot de passe en clair n'est jamais persisté.
            $user->setPassword($hasher->hashPassword($user, $newPassword));
            $user->setMustChangePassword(false);
            $em->flush();

            $this->addFlash('success', 'Le mot de passe est mis à jour. Bienvenue ' . $user->getFirstname() . ' ' . $user->getLastname() . ' !');
            return $this->redirectToRoute('app_dashboard');
        }

        // Premier affichage du formulaire ou affichage après une erreur de validation.
        return $this->render('security/force_change_password.html.twig');
    }
}
