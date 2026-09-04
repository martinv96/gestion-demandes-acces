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
use Symfony\Component\Routing\Attribute\Route;

/* 
    ? AdminController gère les actions liées à l'administration du système.
    ! Il permet de gérer les utilisateurs, les services, les ressources et les rôles.
    ! Seuls les utilisateurs avec le rôle ROLE_ADMIN peuvent accéder à ces fonctionnalités.
*/

#[Route('/admin', name: 'app_admin_')]
final class AdminController extends AbstractController
{
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
        // L'écran d'administration regroupe des données sensibles : accès limité aux administrateurs.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        // Chaque onglet possède sa propre pagination pour éviter qu'un changement d'onglet perde la page courante.
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

        // Liste blanche des évènements d'audit acceptés depuis l'URL.
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

        // La consultation des audits porte toujours sur un mois complet sélectionné.
        $auditStart = new \DateTimeImmutable(sprintf('%04d-%02d-01 00:00:00', $auditYear, $auditMonth));
        $auditEnd = $auditStart->modify('+1 month');
        $auditFilters['dateFrom'] = $auditStart;
        $auditFilters['dateTo'] = $auditEnd;

        $auditOffset = ($auditCurrentPage - 1) * $auditLimit;

        $audits = $loginAuditRepository->findPaginatedWithFilters($auditFilters, $auditOffset, $auditLimit);
        $totalAudits = $loginAuditRepository->countWithFilters($auditFilters);
        $auditMaxPages = (int) ceil(max(1, $totalAudits) / $auditLimit);

        // Les totaux du mois ignorent le filtre d'évènement pour comparer toutes les connexions.
        $statsFilters = $auditFilters;
        unset($statsFilters['eventType']);

        $rawTotals = $loginAuditRepository->getTotalsForPeriod($statsFilters);
        $periodTotals = $loginAuditDailyStatRepository->getTotalsForPeriod($auditStart, $auditEnd);

        // Les anciennes lignes purgées sont agrégées par jour ; les lignes récentes sont lues directement.
        $liveSuccess = $periodTotals['success'] + $rawTotals['success'];
        $liveFailure = $periodTotals['failure'] + $rawTotals['failure'];
        $liveLogout = $periodTotals['logout'] + $rawTotals['logout'];
        $historyTotals = ['purged' => $periodTotals['purged']];
        $historyRecentDays = $loginAuditDailyStatRepository->findRecentDays(30);


        // Regroupe les transitions actives par code pour l'onglet de configuration du workflow.
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



        // Toutes les collections et informations de pagination sont préparées avant le rendu Twig.
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
        // La purge remplace les audits anciens par des statistiques quotidiennes, sans perdre les totaux.
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

    // route pour ajouter un service
    // ! la route /admin/service/add permet d'ajouter un nouveau service via un formulaire.
    #[Route('/service/add', name: 'service_add', methods: ['POST'])]
    public function serviceAdd(
        Request $request,
        EntityManagerInterface $em,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository
    ): Response {
        // Un code de service crée si nécessaire son étape de validation dans le workflow par défaut.
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
        // Changer le code d'un service désactive son ancienne étape et prépare la nouvelle.
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
        // Ne supprime pas les transitions : elles deviennent inactives pour préserver la configuration passée.
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
        // Ajoute une paire validation/refus uniquement lorsqu'aucune étape active n'existe pour ce service.
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
        // Doctrine empêche cette suppression tant que des agents utilisent encore ce service.
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

    // route pour relancer manuellement un service par mail
    //! accesible uniquement par les comptes dotés du rôle ROLE_ADMIN
    #[Route('/request/{id}/remind-service', name: 'request_remind_service', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function remindService(
        AccessRequest $accessRequest,
        Request $request,
        WorkflowNotificationService $notificationService,
        EntityManagerInterface $em
    ): Response {
        // Relance manuelle envoyée par un administrateur au service indiqué depuis le détail d'une demande.
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
        // Le service renvoie le nombre de destinataires trouvés et le nombre d'envois réellement réussis.
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
