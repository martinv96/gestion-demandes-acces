<?php

namespace App\Controller;

use App\Entity\Ressource;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\WorkflowTransitionConfig;
use App\Entity\LoginAudit;
use App\Entity\Request as AccessRequest;
use App\Service\Workflow\WorkflowNotificationService;
use App\Repository\LoginAuditDailyStatRepository;
use App\Repository\LoginAuditRepository;
use App\Service\Security\LoginAuditRetentionService;
use App\Repository\WorkflowTransitionConfigRepository;
use App\Repository\RessourceRepository;
use App\Repository\RoleRepository;
use App\Repository\ServiceRepository;
use App\Repository\UserRepository;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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
        LoginAuditRepository $loginAuditRepository,
        LoginAuditDailyStatRepository $loginAuditDailyStatRepository,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        $limit = 10;
        $userLimit = 8;
        $softwareLimit = 5;
        $tab = $request->query->getString('tab', 'users');

        $serviceCurrentPage = $request->query->getInt('page', 1);
        $usersCurrentPage = $request->query->getInt('users_page', 1);
        $softwareCurrentPage = $request->query->getInt('logiciels_page', 1);


        if ($serviceCurrentPage < 1) {
            $serviceCurrentPage = 1;
        }
        if ($usersCurrentPage < 1) {
            $usersCurrentPage = 1;
        }
        if ($softwareCurrentPage < 1) {
            $softwareCurrentPage = 1;
        }
        $servicesOffset = ($serviceCurrentPage - 1) * $limit;
        $usersOffset = ($usersCurrentPage - 1) * $userLimit;
        $softwareOffset = ($softwareCurrentPage - 1) * $softwareLimit;

        $auditLimit = 15;
        $auditCurrentPage = $request->query->getInt('audit_page', 1);
        if ($auditCurrentPage < 1) {
            $auditCurrentPage = 1;
        }

        $allowedAuditTypes = [
            LoginAudit::EVENT_SUCCESS,
            LoginAudit::EVENT_FAILURE,
            LoginAudit::EVENT_LOGOUT,
        ];

        $auditEventType = (string) $request->query->get('audit_event_type', '');
        $auditMonth = $request->query->getInt('audit_month', (int) (new \DateTimeImmutable('now'))->format('m'));
        $auditYear = $request->query->getInt('audit_year', (int) (new \DateTimeImmutable('now'))->format('Y'));

        if ($auditMonth < 1 || $auditMonth > 12) {
            $auditMonth = (int) (new \DateTimeImmutable('now'))->format('m');
        }
        if ($auditYear < 2000 || $auditYear > (int) (new \DateTimeImmutable('now'))->format('Y')) {
            $auditYear = (int) (new \DateTimeImmutable('now'))->format('Y');
        }

        $auditFilters = [];

        if ($auditEventType !== '' && in_array($auditEventType, $allowedAuditTypes, true)) {
            $auditFilters['eventType'] = $auditEventType;
        } else {
            $auditEventType = '';
        }

        $auditStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $auditYear, $auditMonth));
        $auditEnd = $auditStart->modify('+1 month');
        $auditFilters['dateFrom'] = $auditStart;
        $auditFilters['dateTo'] = $auditEnd;

        $auditOffset = ($auditCurrentPage - 1) * $auditLimit;

        $audits = $loginAuditRepository->findPaginatedWithFilters($auditFilters, $auditOffset, $auditLimit);
        $totalAudits = $loginAuditRepository->countWithFilters($auditFilters);
        $auditMaxPages = (int) ceil(max(1, $totalAudits) / $auditLimit);

        $statsFilters = $auditFilters;
        unset($statsFilters['eventType']);

        $rawTotals = $loginAuditRepository->getTotalsForPeriod($statsFilters);
        $periodTotals = $loginAuditDailyStatRepository->getTotalsForPeriod($auditStart, $auditEnd);

        $liveSuccess = $periodTotals['success'] + $rawTotals['success'];
        $liveFailure = $periodTotals['failure'] + $rawTotals['failure'];
        $liveLogout = $periodTotals['logout'] + $rawTotals['logout'];
        $historyTotals = ['purged' => $periodTotals['purged']];
        $historyRecentDays = $loginAuditDailyStatRepository->findRecentDays(30);


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

        //services
        $paginatedServices = $serviceRepository->findPaginated($servicesOffset, $limit);
        $allServices = $serviceRepository->findBy([], ['name' => 'ASC']);
        $totalServices = $serviceRepository->countAll();
        $servicesMaxPages = (int) ceil($totalServices / $limit);


        //users
        $paginatedUsers = $userRepository->findPaginated($usersOffset, $userLimit);
        $totalUsers = $userRepository->countAll();
        $usersMaxPages = (int) ceil($totalUsers / $userLimit);

        //logiciels
        $category = 'logiciel';
        $paginatedSoftware = $ressourceRepository->findPaginated($category, $softwareOffset, $softwareLimit);
        $totalSoftwares = $ressourceRepository->countAll($category);
        $softwaresMaxPages = (int) ceil($totalSoftwares / $softwareLimit);



        return $this->render('admin/index.html.twig', [
            'tab'       => $request->query->getString('tab', 'users'),

            'users'     => $paginatedUsers,
            'usersCurrentPage' => $usersCurrentPage,
            'usersMaxPages' => $usersMaxPages,
            'totalUsers' => $totalUsers,

            'services'  => $paginatedServices,
            'allServices' => $allServices,
            'currentPage' => $serviceCurrentPage,
            'maxPages' => $servicesMaxPages,
            'total_pages' => $servicesMaxPages,
            'totalServices' => $totalServices,
            'filters' => ['tab' => 'services'],


            'audits' => $audits,
            'auditFilters' => [
                'eventType' => $auditEventType,
            ],
            'auditMonth' => $auditMonth,
            'auditYear' => $auditYear,
            'auditCurrentPage' => $auditCurrentPage,
            'auditMaxPages' => $auditMaxPages,
            'totalAudits' => $totalAudits,
            'liveSuccess' => $liveSuccess,
            'liveFailure' => $liveFailure,
            'liveLogout' => $liveLogout,
            'historyTotals' => $historyTotals,
            'historyRecentDays' => $historyRecentDays,

            'logiciels' => $paginatedSoftware,
            'softwareCurrentPage' => $softwareCurrentPage,
            'softwaresMaxPages' => $softwaresMaxPages,
            'totalSoftwares' => $totalSoftwares,

            'roles'     => $roleRepository->findBy([], ['label' => 'ASC']),
            'workflow_transitions' => $workflowByCode,
            'workflowCodes' => $workflowCodes,
        ]);
    }

    #[Route('/audit/purge', name: 'audit_purge', methods: ['POST'])]
    public function auditPurge(
        Request $request,
        LoginAuditRetentionService $retentionService,
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_audit_purge', (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'audits']);
        }

        $result = $retentionService->purgeOlderThanMonths(12);

        $this->addFlash('success', sprintf(
            'Purge audit terminée (cutoff %s) : %d ligne(s) agrégée(s), %d ligne(s) supprimée(s).',
            $result['cutoff'],
            $result['aggregated_rows'],
            $result['purged_rows']
        ));

        return $this->redirectToRoute('app_admin_index', ['tab' => 'audits']);
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

        // ! Vérifier que l'email n'est pas déjà utilisé
        if ($userRepository->findOneBy(['email' => strtolower(trim($email))]) !== null) {
            $this->addFlash('danger', sprintf('L\'adresse email "%s" est déjà utilisée.', htmlspecialchars($email, ENT_QUOTES)));
            return $this->redirectToRoute('app_admin_index', ['tab' => 'users']);
        }


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
        try {
            $em->remove($user);
            $em->flush();
            $this->addFlash('success', 'Utilisateur supprimé.');
        } catch (ForeignKeyConstraintViolationException $e) {
            $this->addFlash('danger', 'Impossible de supprimer ce compte car il est référencé dans l\'historique des validations. Désactivez-le à la place.');
        }
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
                'fromStatus' => 'en_attente_rh',
                'toStatus' => 'en_attente_validation',
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
                'fromStatus' => 'en_attente_validation',
                'toStatus' => 'traitee',
                'requiredRole' => 'ROLE_ST',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 2,
                'action' => 'refuse',
                'fromStatus' => 'en_attente_validation',
                'toStatus' => 'refusee_st',
                'requiredRole' => 'ROLE_ST',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 3,
                'action' => 'validate',
                'fromStatus' => 'en_attente_validation',
                'toStatus' => 'traitee',
                'requiredRole' => 'ROLE_DSI',
            ],
            [
                'workflowCode' => $workflowCode,
                'stepOrder' => 3,
                'action' => 'refuse',
                'fromStatus' => 'en_attente_validation',
                'toStatus' => 'refusee_dsi',
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

        $code = null;
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
        } catch (UniqueConstraintViolationException $e) {
            $this->addFlash('danger', sprintf('Le code "%s" est déjà utilisé par un autre service.', htmlspecialchars($code, ENT_QUOTES)));
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

        $name = '';

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
        } catch (UniqueConstraintViolationException $e) {
            $this->addFlash('danger', sprintf('Un service nommé "%s" existe déjà.', $name));
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

        $transitions = $workflowTransitionConfigRepository->findBy([
            'workflowCode' => $workflowCode,
            'requiredRole' => $requiredRole,
        ]);

        foreach ($transitions as $transition) {
            $transition->setIsActive(false);
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

        $newStepOrder = 2;
        foreach ($active as $transition) {
            $newStepOrder = max($newStepOrder, (int) $transition->getStepOrder() + 1);
        }

        $validate = new WorkflowTransitionConfig();
        $validate
            ->setWorkflowCode($workflowCode)
            ->setStepOrder($newStepOrder)
            ->setAction('validate')
            ->setFromStatus('en_attente_validation')
            ->setToStatus('traitee')
            ->setRequiredRole($requiredRole)
            ->setIsActive(true);

        $refuse = new WorkflowTransitionConfig();
        $refuse
            ->setWorkflowCode($workflowCode)
            ->setStepOrder($newStepOrder)
            ->setAction('refuse')
            ->setFromStatus('en_attente_validation')
            ->setToStatus('refusee_' . strtolower($serviceCode))
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

        try {
            $em->remove($service);
            $em->flush();
        } catch (ForeignKeyConstraintViolationException $e) {
            $this->addFlash('danger', sprintf('Impossible de supprimer le service "%s" car des agents y sont encore rattachés.', $service->getName()));
            return $this->redirectToRoute('app_admin_index', ['tab' => 'services']);
        }

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

    // route pour relancer manuellement un service par mail
    //! accesible uniquement par les comptes dotés du rôle ROLE_ADMIN
    #[Route('/request/{id}/remind-service', name: 'request_remind_service', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remindService(
        AccessRequest $accessRequest,
        Request $request,
        WorkflowNotificationService $notificationService,
        EntityManagerInterface $em
    ): Response {
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // validation du token CSRF pour sécuriser l'action du bouton 
        if (!$this->isCsrfTokenValid('admin_remind_service_' . $accessRequest->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $accessRequest->getId()]);
        }

        // récupération du service cible envoyé par la vue
        $serviceTarget = trim((string) $request->request->get('service_target', ''));
        $customMessage = trim((string) $request->request->get('custom_message', ''));

        if ($serviceTarget === '') {
            $this->addFlash('danger', 'Veuillez spécifier le service à relancer.');
            return $this->redirectToRoute('app_request_show', ['id' => $accessRequest->getId()]);
        }

        // Déclenchement de l'envoi des e-mails avec statistiques détaillées.
        $stats = $notificationService->sendManualServiceReminder($accessRequest, $serviceTarget, $customMessage);

        // pour enregistrer la date et le service relancé
        if ($stats['sent'] > 0) {
            $accessRequest->setLastManualReminderAt(new \DateTimeImmutable());
            $accessRequest->setLastManualReminderService(strtoupper($serviceTarget));
            $em->flush();
            $this->addFlash('success', sprintf(
                'Relance envoyée au service %s (%d destinataire%s).',
                strtoupper($serviceTarget),
                $stats['sent'],
                $stats['sent'] > 1 ? 's' : ''
            ));
        } elseif ($stats['recipients'] > 0) {
            $this->addFlash('danger', sprintf(
                'Destinataire(s) trouvé(s) pour %s (%d), mais envoi impossible. Vérifiez MAILER_DSN et les logs.',
                strtoupper($serviceTarget),
                $stats['recipients']
            ));
        } else {
            $this->addFlash('warning', sprintf(
                'Aucun destinataire actif trouvé pour le service %s. Vérifiez le code service et les comptes utilisateurs.',
                strtoupper($serviceTarget)
            ));
        }

        //redirection vers page détail 
        return $this->redirectToRoute('app_request_show', ['id' => $accessRequest->getId()]);
    }
}
