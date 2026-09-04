<?php

namespace App\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\Ressource;
use App\Entity\User;
use App\Service\Exception\ClosureMaterialException;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Règles métier de restitution des matériels rattachés à une demande de fermeture.
 * Cette classe modifie l'entité mais ne fait pas de flush : l'appelant maîtrise la transaction.
 */
final class ClosureMaterialService
{
    public function __construct(private EntityManagerInterface $entityManager)
    {
    }

    /**
     * @return array{message: string, newStatus: string}
     */
    public function toggleReturnedMaterial(AccessRequest $closureRequest, int $ressourceId, User $user, bool $isAdmin): array
    {
        $ressource = $this->entityManager->getRepository(Ressource::class)->find($ressourceId);
        if (!$ressource instanceof Ressource || $ressource->getCategory() !== 'materiel') {
            throw new ClosureMaterialException('Matériel introuvable.', 404);
        }

        if (!$this->canManage($ressource, $user, $isAdmin)) {
            throw new ClosureMaterialException('Ce matériel ne relève pas de votre service.', 403);
        }

        $parentRequest = $closureRequest->getParentRequest();
        if (!$parentRequest instanceof AccessRequest || !$parentRequest->getRessources()->contains($ressource)) {
            throw new ClosureMaterialException('Ce matériel n\'est pas lié à la demande d\'origine.', 400);
        }

        if ($closureRequest->getRessources()->contains($ressource)) {
            $closureRequest->removeRessource($ressource);
            $message = 'Matériel marqué comme remis.';
            $newStatus = 'remis';
        } else {
            $closureRequest->addRessource($ressource);
            $message = 'Matériel repassé en non remis.';
            $newStatus = 'non_remis';
        }

        $closureRequest->setUpdateDate(new \DateTimeImmutable());
        if ($closureRequest->getStatus() !== AccessRequest::STATUS_EN_ATTENTE_VALIDATION) {
            $closureRequest->setStatus(AccessRequest::STATUS_EN_ATTENTE_VALIDATION);
        }

        return ['message' => $message, 'newStatus' => $newStatus];
    }

    private function canManage(Ressource $ressource, User $user, bool $isAdmin): bool
    {
        // RH gère tous les matériels ; les autres services ne gèrent que leur périmètre.
        $viewerRole = $this->getWorkflowRoleFromUserService($user);
        if ($viewerRole === null) {
            return $isAdmin;
        }

        if ($viewerRole === 'ROLE_RH') {
            return true;
        }

        return $this->resolveOwnerRole($ressource) === $viewerRole;
    }

    private function getWorkflowRoleFromUserService(User $user): ?string
    {
        $serviceCode = strtoupper(trim((string) ($user->getService()?->getCode() ?? '')));

        return $serviceCode !== '' ? 'ROLE_' . $serviceCode : null;
    }

    private function resolveOwnerRole(Ressource $ressource): string
    {
        // Règle historique basée sur le libellé. Préférer un jour un champ métier de responsable sur Ressource.
        $name = mb_strtolower((string) ($ressource->getName() ?? ''));

        if (str_contains($name, 'ordinateur') || str_contains($name, 'telephone') || str_contains($name, 'téléphone')) {
            return 'ROLE_DSI';
        }

        if (
            str_contains($name, 'cle') || str_contains($name, 'clé') || str_contains($name, 'badge')
            || str_contains($name, 'casque') || str_contains($name, 'gilet') || str_contains($name, 'chaussure')
            || str_contains($name, 'pantalon') || str_contains($name, 'veste') || str_contains($name, 'gant')
            || str_contains($name, 'lunette') || str_contains($name, 'harnais') || str_contains($name, 'masque')
            || str_contains($name, 'protection')
        ) {
            return 'ROLE_ST';
        }

        return 'ROLE_RH';
    }
}