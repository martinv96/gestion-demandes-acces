<?php

namespace App\Controller\Admin;

use App\Entity\Ressource;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Gère les ressources de type logiciel depuis l'administration.
 */
#[Route('/admin', name: 'app_admin_')]
final class SoftwareManagementController extends AbstractController
{
    #[Route('/logiciel/add', name: 'logiciel_add', methods: ['POST'])]
    public function logicielAdd(Request $request, EntityManagerInterface $em): Response
    {
        // Les logiciels sont des ressources ; leur statut initial est "non attribué".
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

    #[Route('/logiciel/{id}/edit', name: 'logiciel_edit', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function logicielEdit(Ressource $logiciel, Request $request, EntityManagerInterface $em): Response
    {
        // Cette action modifie uniquement le libellé de la ressource logicielle.
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

        $logiciel->setName($name);
        $em->flush();
        $this->addFlash('success', 'Logiciel mis à jour.');
        return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
    }

    #[Route('/logiciel/{id}/toggle', name: 'logiciel_toggle', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function logicielToggle(Ressource $logiciel, Request $request, EntityManagerInterface $em): Response
    {
        // Une ressource inactive n'est plus proposée dans les nouvelles demandes.
        $this->denyAccessUnlessGranted('ROLE_ADMIN');

        if (!$this->isCsrfTokenValid('admin_logiciel_toggle_' . $logiciel->getId(), (string) $request->request->get('_token'))) {
            $this->addFlash('danger', 'Token de sécurité invalide.');
            return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
        }

        $logiciel->setIsActive(!$logiciel->isActive());
        $em->flush();
        return $this->redirectToRoute('app_admin_index', ['tab' => 'logiciels']);
    }

    #[Route('/logiciel/{id}/delete', name: 'logiciel_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function logicielDelete(Ressource $logiciel, Request $request, EntityManagerInterface $em): Response
    {
        // La suppression efface la ressource ; préférer la désactivation lorsqu'elle est déjà utilisée.
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