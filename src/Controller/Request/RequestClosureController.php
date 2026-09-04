<?php

namespace App\Controller\Request;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Message\WorkflowNotificationMessage;
use App\Service\ClosureMaterialService;
use App\Service\Exception\ClosureMaterialException;
use App\Service\WorkflowService;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Point d'entrée HTTP des actions propres aux demandes de fermeture.
 * Les règles métier sont volontairement déléguées à ClosureMaterialService.
 */
final class RequestClosureController extends AbstractController
{
    #[Route('/request/{id}/mark-returned/{ressourceId}', name: 'app_request_mark_returned', methods: ['POST'], requirements: ['id' => '\\d+', 'ressourceId' => '\\d+'])]
    public function markReturned(
        AccessRequest $requestEntity,
        int $ressourceId,
        Request $request,
        EntityManagerInterface $entityManager,
        ClosureMaterialService $closureMaterialService,
        WorkflowService $workflowService,
        MessageBusInterface $messageBus,
    ): Response {
        // Une fermeture modifie des ressources sensibles : une session authentifiée est obligatoire.
        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_FULLY');

        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        // L'action accepte aussi bien le formulaire classique que l'ancien appel AJAX.
        $isAjax = $request->isXmlHttpRequest();

        if ($requestEntity->getType() !== AccessRequest::TYPE_FERMETURE) {
            return $this->errorResponse($isAjax, 'warning', 'Action disponible uniquement pour une demande de fermeture.', Response::HTTP_BAD_REQUEST, $requestEntity);
        }

        if ($requestEntity->getStatus() === AccessRequest::STATUS_TRAITEE) {
            return $this->errorResponse($isAjax, 'warning', 'Cette demande est déjà traitée.', Response::HTTP_CONFLICT, $requestEntity);
        }

        // Le jeton est lié à la demande et au matériel : il ne peut pas être réutilisé pour un autre matériel.
        if (!$this->isCsrfTokenValid('mark_returned_' . $requestEntity->getId() . '_' . $ressourceId, (string) $request->request->get('_token'))) {
            return $this->errorResponse($isAjax, 'danger', 'Token de sécurité invalide.', Response::HTTP_FORBIDDEN, $requestEntity);
        }

        $submittedVersion = (int) $request->request->get('version', 0);
        if ($submittedVersion <= 0) {
            return $this->errorResponse($isAjax, 'danger', 'Version de la demande invalide.', Response::HTTP_BAD_REQUEST, $requestEntity);
        }

        // Empêche deux utilisateurs de modifier simultanément le même retour de matériel.
        // Le service vérifie le rattachement du matériel et le droit du service de l'utilisateur.
        try {
            $entityManager->lock($requestEntity, LockMode::OPTIMISTIC, $submittedVersion);
        } catch (OptimisticLockException) {
            return $this->errorResponse($isAjax, 'warning', 'Cette demande a été modifiée entre-temps. Rechargez la page puis réessayez.', Response::HTTP_CONFLICT, $requestEntity);
        }

        try {
            $result = $closureMaterialService->toggleReturnedMaterial(
                $requestEntity,
                $ressourceId,
                $user,
                $this->isGranted('ROLE_ADMIN'),
            );
        } catch (ClosureMaterialException $exception) {
            return $this->errorResponse($isAjax, 'danger', $exception->getMessage(), $exception->getCode(), $requestEntity);
        }

        // Ce calcul doit précéder le flush pour conserver le comportement historique de l'API AJAX.
        $canFinalizeClosureNow = $workflowService->canFinalizeClosureByAnyUser($requestEntity);
        try {
            $entityManager->flush();
        } catch (OptimisticLockException) {
            return $this->errorResponse($isAjax, 'warning', 'Cette demande a été modifiée entre-temps. Rechargez la page puis réessayez.', Response::HTTP_CONFLICT, $requestEntity);
        }

        // La notification est envoyée après l'enregistrement afin qu'elle reflète l'état réellement sauvegardé.
        $messageBus->dispatch(new WorkflowNotificationMessage((int) $requestEntity->getId(), $result['message']));

        if ($isAjax) {
            return new JsonResponse([
                'ok' => true,
                'message' => $result['message'],
                'ressourceId' => $ressourceId,
                'newStatus' => $result['newStatus'],
                'version' => $requestEntity->getVersion(),
                'canFinalizeClosure' => $canFinalizeClosureNow,
            ]);
        }

        $this->addFlash('success', $result['message']);

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }

    private function errorResponse(bool $isAjax, string $flashType, string $message, int $status, AccessRequest $requestEntity): Response
    {
        // Les formulaires classiques reviennent sur le détail ; l'interface AJAX attend un JSON avec le statut HTTP.
        if ($isAjax) {
            return new JsonResponse(['ok' => false, 'message' => $message], $status);
        }

        $this->addFlash($flashType, $message);

        return $this->redirectToRoute('app_request_show', ['id' => $requestEntity->getId()]);
    }
}