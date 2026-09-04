<?php

namespace App\Controller\Admin;

use App\Entity\WorkflowTransitionConfig;
use App\Repository\WorkflowTransitionConfigRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gère les codes et transitions de workflow depuis l'administration.
 */
#[Route('/admin', name: 'app_admin_')]
final class WorkflowManagementController extends AbstractController
{
    #[Route('/workflow/code/add', name: 'workflow_code_add', methods: ['POST'])]
    public function workflowCodeAdd(
        Request $request,
        EntityManagerInterface $em,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository
    ): Response {
        // Initialise les transitions standards lors de la création d'un code de workflow.
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
        if (!preg_match('/^[a-z0-9_\-]{3,50}+$/', $code)) {
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
        // Modèle de départ : RH, puis les validations parallèles ST et DSI.
        return [
            ['workflowCode' => $workflowCode, 'stepOrder' => 1, 'action' => 'validate', 'fromStatus' => 'en_attente_rh', 'toStatus' => 'en_attente_validation', 'requiredRole' => 'ROLE_RH'],
            ['workflowCode' => $workflowCode, 'stepOrder' => 1, 'action' => 'refuse', 'fromStatus' => 'en_attente_rh', 'toStatus' => 'refusee_rh', 'requiredRole' => 'ROLE_RH'],
            ['workflowCode' => $workflowCode, 'stepOrder' => 2, 'action' => 'validate', 'fromStatus' => 'en_attente_validation', 'toStatus' => 'traitee', 'requiredRole' => 'ROLE_ST'],
            ['workflowCode' => $workflowCode, 'stepOrder' => 2, 'action' => 'refuse', 'fromStatus' => 'en_attente_validation', 'toStatus' => 'refusee_st', 'requiredRole' => 'ROLE_ST'],
            ['workflowCode' => $workflowCode, 'stepOrder' => 3, 'action' => 'validate', 'fromStatus' => 'en_attente_validation', 'toStatus' => 'traitee', 'requiredRole' => 'ROLE_DSI'],
            ['workflowCode' => $workflowCode, 'stepOrder' => 3, 'action' => 'refuse', 'fromStatus' => 'en_attente_validation', 'toStatus' => 'refusee_dsi', 'requiredRole' => 'ROLE_DSI'],
        ];
    }

    #[Route('workflow/code/{workflowCode}/disable', name: 'workflow_code_disable', methods: ['POST'])]
    public function workflowCodeDisable(
        string $workflowCode,
        Request $request,
        EntityManagerInterface $em,
        WorkflowTransitionConfigRepository $workflowTransitionConfigRepository
    ): Response {
        // Désactive les transitions sans les supprimer, afin de conserver leur historique de configuration.
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
}