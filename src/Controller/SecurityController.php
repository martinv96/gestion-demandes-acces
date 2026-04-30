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

final class SecurityController extends AbstractController
{
    #[Route('/login', name: 'app_login', methods: ['GET', 'POST'])]
    public function login(AuthenticationUtils $authenticationUtils): Response
    {
        if ($this->getUser() !== null) {
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/login.html.twig', [
            'last_username' => $authenticationUtils->getLastUsername(),
            'error' => $authenticationUtils->getLastAuthenticationError(),
        ]);
    }

    #[Route('/logout', name: 'app_logout', methods: ['GET', 'POST'])]
    public function logout(): void
    {
        throw new LogicException('Cette méthode est interceptée par le firewall logout.');
    }

    #[Route('/change-password', name: 'app_force_change_password', methods: ['GET', 'POST'])]
    public function forceChangePassword(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_USER');

        /** @var User $user */
        $user = $this->getUser();

        if (!$user->isMustChangePassword()) {
            return $this->redirectToRoute('app_dashboard');
        }

        if ($request->isMethod('POST')) {
            if (!$this->isCsrfTokenValid('force_change_password', (string) $request->request->get('_token'))) {
                $this->addFlash('danger', 'Token de sécurité invalide.');
                return $this->redirectToRoute('app_force_change_password');
            }

            $newPassword     = (string) $request->request->get('new_password', '');
            $confirmPassword = (string) $request->request->get('confirm_password', '');

            if (
                strlen($newPassword) < 10 || !preg_match('/[a-z]/', $newPassword) || !preg_match('/[A-Z]/',$newPassword) || !preg_match('/\d/', $newPassword) || !preg_match('/[\W_]/',$newPassword)
                ) {
                $this->addFlash('danger', 'Le mot de passe doit contenir au moins 10 caractères, une minuscule, un chiffre et un caractère spécial.');
                return $this->redirectToRoute('app_force_change_password');
            }

            if ($newPassword !== $confirmPassword) {
                $this->addFlash('danger', 'Les mots de passe ne correspondent pas.');
                return $this->redirectToRoute('app_force_change_password');
            }

            $user->setPassword($hasher->hashPassword($user, $newPassword));
            $user->setMustChangePassword(false);
            $em->flush();

            $this->addFlash('success', 'Le mot de passe est mis à jour. Bienvenue ' . $user->getFirstname() . ' ' . $user->getLastname() . ' !');
            return $this->redirectToRoute('app_dashboard');
        }

        return $this->render('security/force_change_password.html.twig');
    }
}
