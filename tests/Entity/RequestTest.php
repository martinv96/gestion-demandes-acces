<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Request;
use App\Entity\Ressource;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use PHPUnit\Framework\TestCase;

// Vérifie les relations principales de Request:
// gestion des ressources et cohérence bidirectionnelle avec WorkflowHistory.
final class RequestTest extends TestCase
{
    // Teste l'ajout et la suppression de ressources dans une demande.
    public function testAddAndRemoveRessource(): void
    {
        $request = new Request();
        $ressource = (new Ressource())
            ->setName('Office 365')
            ->setCategory('logiciel')
            ->setIsActive(true);

        $request->addRessource($ressource);
        self::assertCount(1, $request->getRessources());

        $request->removeRessource($ressource);
        self::assertCount(0, $request->getRessources());
    }

    // Teste que l'ajout d'un WorkflowHistory à une demande met à jour la relation inverse.
    public function testAddRequestIdSetsBidirectionalRelation(): void
    {
        $request = new Request();
        $user = (new User())
            ->setFirstname('Test')
            ->setLastname('User')
            ->setEmail('test@example.com')
            ->setPassword('x')
            ->setIsActive(true);

        $history = (new WorkflowHistory())
            ->setOldStatus('en_attente_rh')
            ->setNewStatus('en_attente_st')
            ->setCommentary('ok')
            ->setDate(new \DateTimeImmutable())
            ->setUser($user);

        $request->addRequestId($history);

        self::assertCount(1, $request->getRequestId());
        self::assertSame($request, $history->getRequest());
    }
}
