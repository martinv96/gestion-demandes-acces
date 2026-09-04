<?php

namespace App\Controller\Request;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Actions HTTP liées aux commentaires privés des demandes.
 */
final class RequestPrivateCommentController extends AbstractController
{
    #[Route('/request/{id}/private-comment-dsi/add', name: 'app_request_add_private_comment_dsi', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function addPrivateCommentDsi(
        AccessRequest $requestEntity,
        Request $httpRequest,
        EntityManagerInterface $entityManager
    ): Response {
        // Seuls DSI et administrateur peuvent créer une note visible uniquement par la DSI.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        if (!$this->isGranted('ROLE_DSI') && !$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Seule le service informatique a accès à ces notes privées.');
        }

        $currentUser = $this->getUser();
        if (!$currentUser instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // Le jeton CSRF est lié à la demande pour empêcher l'ajout de note sur une autre fiche.
        if (!$this->isCsrfTokenValid('add_private_comment_dsi_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addFlash('danger', 'Token CSRF invalide.');
            return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
        }

        $content = trim((string) $httpRequest->request->get('private_comment_dsi', ''));

        // Une soumission vide ne crée pas d'enregistrement ni de message de succès.
        if ($content !== '') {
            $comment = new \App\Entity\PrivateComment();
            $comment->setContent($content);
            $comment->setAuthor($currentUser);
            $comment->setRequest($requestEntity);
            $comment->setTargetService('DSI');

            $entityManager->persist($comment);
            $entityManager->flush();

            $this->addFlash('success', 'Note privée ajoutée.');
        }

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }
}