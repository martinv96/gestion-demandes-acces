<?php

namespace App\Controller;

use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\WorkflowTransitionConfig;
use App\Repository\WorkflowTransitionConfigRepository;
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

/* 
    ? AdminController gère les actions liées à l'administration du système.
    ! Il permet de gérer les utilisateurs, les services, les ressources et les rôles.
    ! Seuls les utilisateurs avec le rôle ROLE_ADMIN peuvent accéder à ces fonctionnalités.
*/

#[Route('/admin', name: 'app_admin_')]
final class AdminController extends AbstractController
{
    private const TEMP_PASSWORD_ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
    private const TEMP_PASSWORD_LENGTH = 12;

    // route pour afficher le tableau de bord admin
    // ! la route /admin affiche un tableau de bord avec des onglets pour gérer les utilisateurs, les services, les ressources et les rôles.
    #[Route('', name: 'index', methods: ['GET'])]
    public function index(
        Request $request,
        UserRepository $userRepository,
        ServiceRepository $serviceRepository,
        RessourceRepository $ressourceRepository,
        RoleRepository $roleRepository,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $activeTransitions = $workflowTransitionConfigRepository->findBy(
            ['isActive' => true],
            ['workflowCode' => 'ASC', 'stepOrder' => 'ASC', 'action' => 'ASC']
        );

        $workflowByCode = [];
        foreach ($activeTransitions as $transition) {
            $code = (string) $transition->getWorkflowCode();
            if (!isset($workflowByCode[$code])) {
                $workflowByCode[$code] = [];
            }
            $workflowByCode[$code][] = $transition;
        }

        $workflowCodes = array_keys($workflowByCode);
        sort($workflowCodes);

        return $this->render('admin/index.html.twig', [
            'tab'       => $request->query->getString('tab', 'users'),
            'users'     => $userRepository->findBy([], ['lastname' => 'ASC']),
            'services'  => $serviceRepository->findBy([], ['name' => 'ASC']),
            'logiciels' => $ressourceRepository->findBy(['category' => 'logiciel'], ['name' => 'ASC']),
            'roles'     => $roleRepository->findBy([], ['label' => 'ASC']),
            'workflow_transitions' => $workflowByCode,
            'workflowCodes' => $workflowCodes,
        ]);
    }

    // route pour ajouter un utilisateur
    // ! la route /admin/user/add permet d'ajouter un nouvel utilisateur via un formulaire.
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

        // ! vérification du token CSRF pour éviter les attaques de type Cross-Site Request Forgery.
        if (!$this->isCsrfTokenValid('admin_user_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $firstname  = trim((string) $request->request->get('firstname', ''));
        $lastname   = trim((string) $request->request->get('lastname', ''));
        $email      = trim((string) $request->request->get('email', ''));
        $roleId     = (int) $request->request->get('role_id', 0);
        $serviceId  = (int) $request->request->get('service_id', 0);


        // ! validation des champs du formulaire : les champs prénom, nom et email sont obligatoires.
        if ($firstname === '' || $lastname === '' || $email === '') {
            $this->addFlash('danger', 'Tous les champs sont obligatoires.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }

        $role    = $roleId    > 0 ? $roleRepository->find($roleId)       : null;
        $service = $serviceId > 0 ? $serviceRepository->find($serviceId) : null;
        // ! Vérifier que l'email n'est pas déjà utilisé
        if ($userRepository->findOneBy(['email' => strtolower(trim($email))]) !== null) {
            $this->addFlash('danger', sprintf('L\'adresse email "%s" est déjà utilisée.', htmlspecialchars($email, ENT_QUOTES)));
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }


        $tempPassword = $this->generateTemporaryPassword();

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
    // ! la route /admin/user/{id}/edit permet de modifier les informations d'un utilisateur existant via un formulaire.
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
    // ! la route /admin/user/{id}/toggle permet d'activer ou de désactiver un utilisateur existant.
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
    // ! la route /admin/user/{id}/delete permet de supprimer un utilisateur existant du système.
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
    // ! la route /admin/user/{id}/reset-password permet de réinitialiser le mot de passe d'un utilisateur existant.
    // ! un nouveau mot de passe temporaire est généré et affiché à l'administrateur après la réinitialisation.
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

        $tempPassword = $this->generateTemporaryPassword();

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

    private function generateTemporaryPassword(): string
    {
        $password = '';
        $maxIndex = strlen(self::TEMP_PASSWORD_ALPHABET) - 1;

        for ($i = 0; $i < self::TEMP_PASSWORD_LENGTH; $i++) {
            $password .= self::TEMP_PASSWORD_ALPHABET[random_int(0, $maxIndex)];
        }

        return $password;
    }

    // ! la route /workflow/code/add permet d'ajouter une nouvelle transition de workflow via un formulaire.
    #[Route('/workflow/code/add', name: 'workflow_code_add', methods: ['POST'])]
    public function workflowCodeAdd(
        Request $request,
        EntityManagerInterface $em,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_workflow_code_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
        }

        $code = strtolower(trim((string) $request->request->get('workflow_code', '')));
        if ($code === '') {
            $this->addFlash('danger', 'Le code du workflow est obligatoire.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
        }
        if (!preg_match('/^[a-z0-9_\\-]{3,50}+$/', $code)) {
            $this->addFlash('danger', 'Le code du workflow ne peut contenir que des lettres minuscules, des chiffres et des underscores.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
        }

        $existing = $workflowTransitionConfigRepository->findOneBy([
            'workflowCode' => $code,
            'isActive' => true,
        ]);

        if (!$existing instanceof WorkflowTransitionConfig) {
            $this->addFlash('danger', sprintf('Le code workflow "%s" est obligatoire.', $code));
            return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
        }

        foreach ($this->getDefaultTransitionsForCode($code) as $row) {
            $transition = new WorkflowTransitionConfig();
            $transition->setWorkflowCode($code)
                ->setStepOrder($row['stepOrder'])
                ->setAction($row['action'])
                ->setFromStatus($row['fromStatus'])
                ->setToStatus($row['toStatus'])
                ->setIsActive(true);
            $em->persist($transition);
        }
        $em->flush();

        $this->addFlash('success', sprintf('Workflow "%s" créé avec les transitions par défaut.', $code));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
    }

    /** 
     * @return array<int, array{workflowCode: string, stepOrder: int, action: string, fromStatus: string, toStatus: string, requiredRole: string}>
     */

    private function getDefaultTransitionsForCode(string $workflowCode): array
    {
        return [
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 1,
                'action' => 'validate',
                'fromStatus' => 'en attente_rh',
                'toStatus' => 'en_attente_st',
                'requiredRole' => 'ROLE_RH',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 1,
                'action' => 'refuse',
                'fromStatus' => 'en_attente_rh',
                'toStatus' => 'refusee_rh',
                'requiredRole' => 'ROLE_RH',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 2,
                'action' => 'validate',
                'fromStatus' => 'en_attente_st',
                'toStatus' => 'en_attente_dsi',
                'requiredRole' => 'ROLE_ST',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 2,
                'action' => 'refuse',
                'fromStatus' => 'en_attente_st',
                'toStatus' => 'en_attente_st',
                'requiredRole' => 'ROLE_ST',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 3,
                'action' => 'validate',
                'fromStatus' => 'en_attente_dsi',
                'toStatus' => 'traitee',
                'requiredRole' => 'ROLE_DSI',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 3,
                'action' => 'rufuse',
                'fromStatus' => 'en_attente_dsi',
                'toStatus' => 'en_attente_st',
                'requiredRole' => 'ROLE_DSI',
            ],
        ];
    }

    // ! la route /workflow/code/{workflowCode}/disable permet de désactiver un workflow existant, ce qui le rend inactif dans le système.
    #[Route('workflow/code/{workflowCode}/disable', name: 'workflow_code_disable', methods: ['POST'])]
    public function workflowCodeDisable(
        string $workflowCode,
        Request $request,
        EntityManagerInterface $em,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_workflow_code_disable_' . $workflowCode, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
        }

        $transitions = $workflowTransitionConfigRepository->findBy([
            'workflowCode' => $workflowCode,
            'isActive' => true,
        ]);

        if ($transitions === []) {
            $this->addFlash('warning', sprintf('Aucune transition active trouvée pour "%s".', $workflowCode));
            return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
        }

        foreach ($transitions as $transition) {
            $transition->setIsActive(false);
        }

        $em->flush();

        $this->addFlash('success', sprintf('Workflow "%s" désactivé.', $workflowCode));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'workflow']);
    }

    // route pour ajouter un service
    // ! la route /admin/service/add permet d'ajouter un nouveau service via un formulaire.
    #[Route('/service/add', name: 'service_add', methods: ['POST'])]
    public function serviceAdd(
        Request $request,
        EntityManagerInterface $em,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_service_add', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        try {
            $name  = trim((string) $request->request->get('name', ''));
            $email = trim((string) $request->request->get('email', ''));
            $code = $this->normalizeServiceWorkflowCode((string) $request->request->get('code', ''));

            if ($name === '') {
                $this->addFlash('danger', 'Le nom du service est obligatoire.');
                return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
            }

            if ($code !== null) {
                $this->ensureWorkflowStepExistsForServiceCode($code, $workflowTransitionConfigRepository, $em);
            }

            $service = new Service();
            $service->setName($name)->setEmail($email ?: '')->setCode($code);
            $em->persist($service);
            $em->flush();
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        $this->addFlash('success', sprintf('Service "%s" créé.', $name));
        return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
    }

    // route pour modifier un service
    // ! la route /admin/service/{id}/edit permet de modifier les informations d'un service existant via un formulaire.
    #[Route('/service/{id}/edit', name: 'service_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function serviceEdit(
        Service $service,
        Request $request,
        EntityManagerInterface $em,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // ! vérification du token CSRF pour éviter les attaques de type Cross-Site Request Forgery.
        // ! cross-site request forgery (CSRF) est une attaque qui consiste à faire exécuter une action non désirée par un utilisateur authentifié sur une application web.
        if (!$this->isCsrfTokenValid('admin_service_edit_' . $service->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        try {
            $name  = trim((string) $request->request->get('name', ''));
            $email = trim((string) $request->request->get('email', ''));
            $code = $this->normalizeServiceWorkflowCode((string) $request->request->get('code', ''));
            $oldCode = $service->getCode();

            if ($name === '') {
                $this->addFlash('danger', 'Le nom du service est obligatoire.');
                return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
            }

            // Si le code a changé, nettoyer l'ancien
            if ($oldCode !== $code) {
                if ($oldCode !== null) {
                    $this->removeWorkflowStepForServiceCode($oldCode, $workflowTransitionConfigRepository, $em);
                }
                // Créer le nouveau s'il existe
                if ($code !== null) {
                    $this->ensureWorkflowStepExistsForServiceCode($code, $workflowTransitionConfigRepository, $em);
                }
            }

            $service->setName($name)->setEmail($email ?: $service->getEmail())->setCode($code);
            $em->flush();
        } catch (\InvalidArgumentException $e) {
            $this->addFlash('danger', $e->getMessage());
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

        $this->addFlash('success', 'Service mis à jour.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
    }

    // route pour activer/désactiver un service
    // ! la route /admin/service/{id}/toggle permet d'activer ou de désactiver un service existant.
    private function removeWorkflowStepForServiceCode(
        string $serviceCode,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
        EntityManagerInterface $em
    ): void {
        $workflowCode = 'default_access';
        $requiredRole = 'ROLE_' . $serviceCode;
        $pendingStatus = 'en_attente_' . strtolower($serviceCode);

        // Marquer toutes les transitions ROLE_{serviceCode} comme inactives
        $transitions = $workflowTransitionConfigRepository->findBy([
            'workflowCode' => $workflowCode,
            'requiredRole' => $requiredRole,
        ]);

        foreach ($transitions as $transition) {
            $transition->setIsActive(false);
        }

        // Restaurer la transition qui pointait vers en_attente_{serviceCode}
        // (elle doit revenir à 'traitee'), meme si elle a ete desactivee manuellement.
        $allTransitions = $workflowTransitionConfigRepository->findBy([
            'workflowCode' => $workflowCode,
            'toStatus' => $pendingStatus,
        ]);

        foreach ($allTransitions as $transition) {
            $transition->setToStatus('traitee');
            $transition->setIsActive(true);
        }

        // Filet de securite: si la transition coeur DSI->traitee a ete supprimee,
        // on la recree pour conserver le parcours RH -> ST -> DSI.
        $coreDsiValidate = $workflowTransitionConfigRepository->findOneBy([
            'workflowCode' => $workflowCode,
            'action' => 'validate',
            'fromStatus' => 'en_attente_dsi',
            'requiredRole' => 'ROLE_DSI',
        ]);

        if (!$coreDsiValidate instanceof WorkflowTransitionConfig) {
            $coreDsiValidate = new WorkflowTransitionConfig();
            $coreDsiValidate
                ->setWorkflowCode($workflowCode)
                ->setStepOrder(3)
                ->setAction('validate')
                ->setFromStatus('en_attente_dsi')
                ->setToStatus('traitee')
                ->setRequiredRole('ROLE_DSI')
                ->setIsActive(true);
            $em->persist($coreDsiValidate);
        }
    }

    // Normalise et valide le code workflow d'un service, ou retourne null si aucun code n'est fourni.
    private function normalizeServiceWorkflowCode(string $rawCode): ?string
    {
        $code = strtoupper(trim($rawCode));

        if ($code === '') {
            return null;
        }

        if (!preg_match('/^[A-Z0-9_-]{2,20}$/', $code)) {
            throw new \InvalidArgumentException('Code workflow invalide (2-20, lettres/chiffres/_/-).');
        }

        return $code;
    }

    // Assure que les étapes de workflow nécessaires pour un code de service donné existent, sinon les crée.
    private function ensureWorkflowStepExistsForServiceCode(
        string $serviceCode,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
        EntityManagerInterface $em
    ): void {
        $workflowCode = 'default_access';
        $requiredRole = 'ROLE_' . $serviceCode;
        $pendingStatus = 'en_attente_' . strtolower($serviceCode);

        $alreadyExists = $workflowTransitionConfigRepository->findOneBy([
            'workflowCode' => $workflowCode,
            'requiredRole' => $requiredRole,
            'isActive' => true,
        ]);

        if ($alreadyExists instanceof WorkflowTransitionConfig) {
            return;
        }

        $active = $workflowTransitionConfigRepository->findBy(
            ['workflowCode' => $workflowCode, 'isActive' => true],
            ['stepOrder' => 'ASC', 'action' => 'ASC']
        );

        $finalValidate = null;
        foreach ($active as $transition) {
            if ($transition->getAction() === 'validate' && $transition->getToStatus() === 'traitee') {
                $finalValidate = $transition;
            }
        }

        if (!$finalValidate instanceof WorkflowTransitionConfig) {
            return;
        }

        $previousFinalFromStatus = (string) $finalValidate->getFromStatus();
        $newStepOrder = (int) $finalValidate->getStepOrder() + 1;

        $finalValidate->setToStatus($pendingStatus);

        $validate = new WorkflowTransitionConfig();
        $validate
            ->setWorkflowCode($workflowCode)
            ->setStepOrder($newStepOrder)
            ->setAction('validate')
            ->setFromStatus($pendingStatus)
            ->setToStatus('traitee')
            ->setRequiredRole($requiredRole)
            ->setIsActive(true);

        $refuse = new WorkflowTransitionConfig();
        $refuse
            ->setWorkflowCode($workflowCode)
            ->setStepOrder($newStepOrder)
            ->setAction('refuse')
            ->setFromStatus($pendingStatus)
            ->setToStatus($previousFinalFromStatus)
            ->setRequiredRole($requiredRole)
            ->setIsActive(true);

        $em->persist($validate);
        $em->persist($refuse);
    }

    // route pour supprimer un service
    // ! la route /admin/service/{id}/delete permet de supprimer un service existant.
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
    // ! la route /admin/logiciel/add permet d'ajouter un nouveau logiciel via un formulaire.
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
    // ! la route /admin/logiciel/{id}/edit permet de modifier les informations d'un logiciel existant via un formulaire.
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

    // ! la route /admin/logiciel/{id}/toggle permet d'activer ou de désactiver un logiciel existant.
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
    // ! la route /admin/logiciel/{id}/delete permet de supprimer un logiciel existant.
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
