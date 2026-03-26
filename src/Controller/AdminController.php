<?php

namespace App\Controller;

use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Repository\RessourceRepository;
use App\Repository\RoleRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/admin', name: 'app_admin_')]
final class AdminController extends AbstractController
{
    // route pour afficher le tableau de bord admin
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
        ServiceRepository $serviceRepository,
        RessourceRepository $ressourceRepository,
        RoleRepository $roleRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        return $this->render('admin/index.html.twig', [
            'tab'       => $request->query->getString('tab', 'users'),
            'users'     => $userRepository->findBy([], ['lastname' => 'ASC']),
            'services'  => $serviceRepository->findBy([], ['name' => 'ASC']),
            'logiciels' => $ressourceRepository->findBy(['category' => 'logiciel'], ['name' => 'ASC']),
            'roles'     => $roleRepository->findBy([], ['label' => 'ASC']),
        ]);
    }

    // route pour ajouter un utilisateur
    #[Route('/user/add', name: 'user_add', methods: ['POST'])]
    public function userAdd(
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
        RoleRepository $roleRepository,
        ServiceRepository $serviceRepository,
        UserRepository $userRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $firstname  = trim((string) $request->request->get('firstname', ''));
        $lastname   = trim((string) $request->request->get('lastname', ''));
        $email      = trim((string) $request->request->get('email', ''));
        $roleId     = (int) $request->request->get('role_id', 0);
        $serviceId  = (int) $request->request->get('service_id', 0);

        if ($firstname === '' || $lastname === '' || $email === '') {
            $this->addFlash('danger', 'Tous les champs sont obligatoires.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $role    = $roleId    > 0 ? $roleRepository->find($roleId)       : null;
        $service = $serviceId > 0 ? $serviceRepository->find($serviceId) : null;
        // Vérifier que l'email n'est pas déjà utilisé
        if ($userRepository->findOneBy(['email' => strtolower(trim($email))]) !== null) {
            $this->addFlash('danger', sprintf('L\'adresse email "%s" est déjà utilisée.', htmlspecialchars($email, ENT_QUOTES)));
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }


        // Alphabet sans caractères ambigus (pas l/1/I/i, pas O/0)
        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $tempPassword = '';
        for ($i = 0; $i < 12; $i++) {
            $tempPassword .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $user = new User();
        $user->setFirstname($firstname)
             ->setLastname($lastname)
             ->setEmail($email)
             ->setIsActive(true)
             ->setMustChangePassword(true)
             ->setRole($role)
             ->setService($service);
        $user->setPassword($hasher->hashPassword($user, $tempPassword));

        $em->persist($user);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Utilisateur "%s %s" créé. Mot de passe provisoire : '
            . '<strong class="font-monospace user-select-all">%s</strong> '
            . '<button type="button" class="btn btn-sm btn-outline-light ms-2 py-0" '
            . 'onclick="navigator.clipboard.writeText(\'%s\').then(()=>this.textContent=\'Copié !\')">Copier</button>',
            htmlspecialchars($firstname, ENT_QUOTES),
            htmlspecialchars($lastname, ENT_QUOTES),
            $tempPassword,
            $tempPassword
        ));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    // route pour modifier un utilisateur
    #[Route('/user/{id}/edit', name: 'user_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userEdit(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        RoleRepository $roleRepository,
        ServiceRepository $serviceRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_edit_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $firstname  = trim((string) $request->request->get('firstname', ''));
        $lastname   = trim((string) $request->request->get('lastname', ''));
        $email      = trim((string) $request->request->get('email', ''));
        $roleId     = (int) $request->request->get('role_id', 0);
        $serviceId  = (int) $request->request->get('service_id', 0);

        if ($firstname === '' || $lastname === '' || $email === '') {
            $this->addFlash('danger', 'Nom, prénom et email sont obligatoires.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $role    = $roleId    > 0 ? $roleRepository->find($roleId)       : null;
        $service = $serviceId > 0 ? $serviceRepository->find($serviceId) : null;

        $user->setFirstname($firstname)
             ->setLastname($lastname)
             ->setEmail($email)
             ->setRole($role)
             ->setService($service);

        $em->flush();
        $this->addFlash('success', 'Utilisateur mis à jour.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    // route pour activer/désactiver un utilisateur
    #[Route('/user/{id}/toggle', name: 'user_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userToggle(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_toggle_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $user->setIsActive(!$user->isActive());
        $em->flush();
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    // route pour supprimer un utilisateur
    #[Route('/user/{id}/delete', name: 'user_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userDelete(User $user, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if ($user === $this->getUser()) {
            $this->addFlash('danger', 'Vous ne pouvez pas supprimer votre propre compte.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        if (!$this->isCsrfTokenValid('admin_user_delete_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $em->remove($user);
        $em->flush();
        $this->addFlash('success', 'Utilisateur supprimé.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    // route pour réinitialiser le mot de passe d'un utilisateur
    #[Route('/user/{id}/reset-password', name: 'user_reset_password', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function userResetPassword(
        User $user,
        Request $request,
        EntityManagerInterface $em,
        UserPasswordHasherInterface $hasher,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_user_reset_password_' . $user->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $alphabet = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
        $tempPassword = '';
        for ($i = 0; $i < 12; $i++) {
            $tempPassword .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        $user->setPassword($hasher->hashPassword($user, $tempPassword));
        $user->setMustChangePassword(true);
        $em->flush();

        $this->addFlash('success', sprintf(
            'Mot de passe de %s %s réinitialisé. Nouveau mot de passe provisoire : '
            . '<strong class="font-monospace user-select-all">%s</strong> '
            . '<button type="button" class="btn btn-sm btn-outline-light ms-2 py-0" '
            . 'onclick="navigator.clipboard.writeText(\'%s\').then(()=>this.textContent=\'Copié !\')">Copier</button>',
            htmlspecialchars($user->getFirstname() ?? '', ENT_QUOTES),
            htmlspecialchars($user->getLastname() ?? '', ENT_QUOTES),
            $tempPassword,
            $tempPassword
        ));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
    }

    // route pour ajouter un service
    #[Route('/service/add', name: 'service_add', methods: ['POST'])]
    public function serviceAdd(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_service_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        $name  = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $code  = trim((string) $request->request->get('code', ''));

        if ($name === '') {
            $this->addFlash('danger', 'Le nom du service est obligatoire.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        $service = new Service();
        $service->setName($name)->setEmail($email ?: '')->setCode($code ?: null);
        $em->persist($service);
        $em->flush();

        $this->addFlash('success', sprintf('Service "%s" créé.', $name));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
    }

    // route pour modifier un service
    #[Route('/service/{id}/edit', name: 'service_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function serviceEdit(Service $service, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_service_edit_' . $service->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        $name  = trim((string) $request->request->get('name', ''));
        $email = trim((string) $request->request->get('email', ''));
        $code  = trim((string) $request->request->get('code', ''));

        if ($name === '') {
            $this->addFlash('danger', 'Le nom du service est obligatoire.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        $service->setName($name)->setEmail($email ?: $service->getEmail())->setCode($code ?: null);
        $em->flush();
        $this->addFlash('success', 'Service mis à jour.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
    }

    // route pour supprimer un service
    #[Route('/service/{id}/delete', name: 'service_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function serviceDelete(Service $service, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_service_delete_' . $service->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        $em->remove($service);
        $em->flush();
        $this->addFlash('success', 'Service supprimé.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
    }

    // route pour ajouter un logiciel
    #[Route('/logiciel/add', name: 'logiciel_add', methods: ['POST'])]
    public function logicielAdd(Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_logiciel_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('danger', 'Le nom du logiciel est obligatoire.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
        }

        $logiciel = new Ressource();
        $logiciel
            ->setName($name)
            ->setCategory('logiciel')
            ->setAssignmentStatus(Ressource::ASSIGNMENT_NON_ATTRIBUE)
            ->setIsActive(true);
        $em->persist($logiciel);
        $em->flush();

        $this->addFlash('success', sprintf('Logiciel "%s" ajouté.', $name));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
    }

    // route pour modifier un logiciel
    #[Route('/logiciel/{id}/edit', name: 'logiciel_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function logicielEdit(Ressource $logiciel, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_logiciel_edit_' . $logiciel->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
        }

        $name = trim((string) $request->request->get('name', ''));
        if ($name === '') {
            $this->addFlash('danger', 'Le nom du logiciel est obligatoire.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
        }

        $logiciel
            ->setName($name);
        $em->flush();
        $this->addFlash('success', 'Logiciel mis à jour.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
    }

    // route pour activer ou désactiver un logiciel
    #[Route('/logiciel/{id}/toggle', name: 'logiciel_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function logicielToggle(Ressource $logiciel, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_logiciel_toggle_' . $logiciel->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
        }

        $logiciel->setIsActive(!$logiciel->isActive());
        $em->flush();
        return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
    }
    
    // route pour supprimer un logiciel
    #[Route('/logiciel/{id}/delete', name: 'logiciel_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function logicielDelete(Ressource $logiciel, Request $request, EntityManagerInterface $em): Response
    {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_logiciel_delete_' . $logiciel->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
        }

        $em->remove($logiciel);
        $em->flush();
        $this->addFlash('success', 'Logiciel supprimé.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
    }
}
