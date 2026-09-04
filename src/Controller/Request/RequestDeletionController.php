<?php

namespace App\Controller\Request;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Actions HTTP de suppression des demandes d'accès.
 */
final class RequestDeletionController extends AbstractController
{
    #[Route('/request/{id}/delete', name: 'app_request_delete', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function deleteRequest(
        AccessRequest $requestEntity,
        Request $httpRequest,
        EntityManagerInterface $entityManager
    ): Response {
        // La suppression est définitive : l'utilisateur doit être connecté et autorisé ci-dessous.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        /** @var User $currentUser */
        $currentUser = $this->getUser();

        $author = $requestEntity->getAuthor();

        $isAdmin = $this->isGranted('ROLE_ADMIN');
        $isRh = $this->isGranted('ROLE_RH');
        $isAuthor = ($author === $currentUser);

        // Un auteur RH garde le droit de supprimer sa propre demande selon la règle historique.
        $authorIsRh = $author && (
            in_array('ROLE_RH', $author->getRoles(), true) ||
            ($author->getService() && $author->getService()->getName() === 'RH')
        );

        $canDelete = false;

        if ($isAdmin) {
            $canDelete = true;
        } elseif ($isRh) {
            if ($requestEntity->getStatus() === 'a_valider_rh' || $authorIsRh) {
                $canDelete = true;
            }
        } elseif ($isAuthor) {
            if ($requestEntity->getStatus() === 'a_valider_rh') {
                $canDelete = true;
            }
        }

        // Les droits sont établis avant la vérification CSRF pour ne pas exposer d'action non autorisée.
        if (!$canDelete) {
            $this->addRequestFlash($httpRequest, 'danger', 'Vous n\'avez pas les droits pour supprimer cette demande validée.');
            $referer = $httpRequest->headers->get('referer');
            return $referer ? $this->redirect($referer) : $this->redirectToRoute('app_my_requests');
        }

        if (!$this->isCsrfTokenValid('request_delete_' . $requestEntity->getId(), (string) $httpRequest->request->get('_token'))) {
            $this->addRequestFlash($httpRequest, 'danger', 'Token de sécurité invalide.');
            $referer = $httpRequest->headers->get('referer');
            return $referer ? $this->redirect($referer) : $this->redirectToRoute('app_my_requests');
        }

        try {
            // Retire d'abord les relations pour respecter les contraintes de clés étrangères.
            foreach ($requestEntity->getRequestId()->toArray() as $history) {
                $entityManager->remove($history);
            }

            foreach ($requestEntity->getRessources()->toArray() as $ressource) {
                $requestEntity->removeRessource($ressource);
            }

            foreach ($requestEntity->getChildRequests()->toArray() as $childRequest) {
                $childRequest->setParentRequest(null);
            }

            $entityManager->remove($requestEntity);
            $entityManager->flush();

            $this->addRequestFlash($httpRequest, 'success', 'La demande a bien été supprimée.');
        } catch (ForeignKeyConstraintViolationException) {
            $this->addRequestFlash($httpRequest, 'danger', 'Suppression impossible : des éléments liés à la demande doivent être traités avant suppression.');
        } catch (\Throwable $e) {
            $this->addRequestFlash($httpRequest, 'danger', 'Suppression impossible pour le moment.');
        }

        // Retourne à la page d'origine lorsqu'elle est connue, sinon à la liste personnelle.
        $referer = $httpRequest->headers->get('referer');
        return $referer ? $this->redirect($referer) : $this->redirectToRoute('app_my_requests');
    }

    private function addRequestFlash(Request $httpRequest, string $type, string $message): void
    {
        if (!$httpRequest->hasSession()) {
            return;
        }

        $session = $httpRequest->getSession();
        if (!$session instanceof FlashBagAwareSessionInterface) {
            return;
        }

        $session->getFlashBag()->add($type, $message);
    }
}