<?php

namespace App\Controller\Admin;

use App\Entity\User;
use App\Repository\RoleRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gère les comptes utilisateurs depuis l'administration.
 */
#[Route('/admin', name: 'app_admin_')]
final class UserManagementController extends AbstractController
{
    // Alphabet sans caractères ambigus (O/0, I/1) pour les mots de passe affichés à l'administrateur.
    private const TEMP_PASSWORD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    private const TEMP_PASSWORD_LENGTH = 12;

    #[Route('/user/add', name: 'user_add', methods: ['POST'])]
    public function userAdd(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        RoleRepository $roleRepository,
        ServiceRepository $serviceRepository,
        UserRepository $userRepository,
    ): Response {
        // Crée un compte actif avec un mot de passe provisoire à modifier lors de la première connexion.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $email      = trim((string) $request->request->get('email', ''));
        $firstName  = trim((string) $request->request->get('first_name', ''));
        $lastName   = trim((string) $request->request->get('last_name', ''));
        $roleId     = (int) $request->request->get('role_id', 0);
        $serviceId  = (int) $request->request->get('service_id', 0);

        if ($email === '' || $firstName === '' || $lastName === '') {
            $this->addFlash('danger', 'L\'email, le prénom et le nom sont obligatoires.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $role    = $roleId > 0 ? $roleRepository->find($roleId) : null;
        $service = $serviceId > 0 ? $serviceRepository->find($serviceId) : null;

        if ($service === null) {
            $this->addFlash('danger', 'Le service est obligatoire.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        if ($userRepository->findOneBy(['email' => strtolower(trim($email))]) !== null) {
            $this->addFlash('danger', sprintf('L\'adresse email "%s" est déjà utilisée.', htmlspecialchars($email, ENT_QUOTES)));
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        // Le mot de passe n'est affiché qu'immédiatement après la création : il n'est jamais stocké en clair.
        $tempPassword = $this->generateTemporaryPassword();

        $user = new User();
        $user->setEmail($email)
            ->setFirstName($firstName)
            ->setLastName($lastName)
            ->setIsActive(true)
            ->setMustChangePassword(true)
            ->setRole($role)
            ->setService($service);
        $user->setPassword($hasher->hashPassword($user, $tempPassword));

        $em->persist($user);
        $em->flush();

        $this->addFlash('success', sprintf(
            ' Compte "%s" créé. Mot de passe provisoire : '
                . '<strong class="font-monospace user-select-all">%s</strong> '
                . '<button type="button" class="btn btn-sm btn-outline-light ms-2 py-0 js-copy-temp-password" '
                . 'data-copy-text="%s">Copier</button>',
            htmlspecialchars('Compte ' . ($service->getName() ?? 'Service'), ENT_QUOTES),
            $tempPassword,
            htmlspecialchars($tempPassword, ENT_QUOTES)
        ));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    #[Route('/user/{id}/edit', name: 'user_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userEdit(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        RoleRepository $roleRepository,
        ServiceRepository $serviceRepository,
    ): Response {
        // Met à jour les informations d'un compte existant, sans modifier son mot de passe.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_edit_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $email      = trim((string) $request->request->get('email', ''));
        $firstName  = trim((string) $request->request->get('first_name', ''));
        $lastName   = trim((string) $request->request->get('last_name', ''));
        $roleId     = (int) $request->request->get('role_id', 0);
        $serviceId  = (int) $request->request->get('service_id', 0);

        if ($email === '' || $firstName === '' || $lastName === '') {
            $this->addFlash('danger', 'L\'email, le nom et le prénom sont obligatoires.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $role    = $roleId > 0 ? $roleRepository->find($roleId) : null;
        $service = $serviceId > 0 ? $serviceRepository->find($serviceId) : null;

        if ($service === null) {
            $this->addFlash('danger', 'Le service est obligatoire.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $user->setEmail($email)
            ->setFirstname($firstName)
            ->setLastname($lastName)
            ->setRole($role)
            ->setService($service);

        $em->flush();
        $this->addFlash('success', 'Utilisateur mis à jour.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    #[Route('/user/{id}/toggle', name: 'user_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userToggle(User $user, Request $request, EntityManagerInterface $em): Response
    {
        // Désactiver un compte bloque la prochaine authentification sans supprimer son historique.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_toggle_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $user->setIsActive(!$user->isActive());
        $em->flush();
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    #[Route('/user/{id}/delete', name: 'user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userDelete(User $user, Request $request, EntityManagerInterface $em): Response
    {
        // La suppression est réservée aux comptes sans relations bloquantes dans l'historique.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }
        try {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'Utilisateur supprimé.');
        } catch (ForeignKeyConstraintViolationException $e) {
            $this->addFlash('danger', 'Impossible de supprimer ce compte car il est référencé dans l\'historique des validations. Désactivez-le à la place.');
        }
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    #[Route('/user/{id}/reset-password', name: 'user_reset_password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userResetPassword(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        // Génère un nouveau mot de passe et force son changement au prochain accès.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_reset_password_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $tempPassword = $this->generateTemporaryPassword();

        $user->setPassword($hasher->hashPassword($user, $tempPassword));
        $user->setMustChangePassword(true);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Mot de passe de %s réinitialisé. Nouveau mot de passe provisoire : '
                . '<strong class="font-monospace user-select-all">%s</strong> '
                . '<button type="button" class="btn btn-sm btn-outline-light ms-2 py-0 js-copy-temp-password" '
                . 'data-copy-text="%s">Copier</button>',
            htmlspecialchars($user->getDisplayName(), ENT_QUOTES),
            $tempPassword,
            htmlspecialchars($tempPassword, ENT_QUOTES)
        ));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    private function generateTemporaryPassword(): string
    {
        // random_int() fournit un tirage cryptographiquement sûr adapté à un mot de passe temporaire.
        $password = '';
        $maxIndex = strlen(self::TEMP_PASSWORD_ALPHABET) - 1;

        for ($i = 0; $i < self::TEMP_PASSWORD_LENGTH; $i++) {
            $password .= self::TEMP_PASSWORD_ALPHABET[random_int(0, $maxIndex)];
        }

        return $password;
    }
}