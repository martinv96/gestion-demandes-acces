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

    // teste que la methode getReference génère une référence correcte en fonction du type de demande (ouverture).
    public function testGetReferenceUsesOpeningPrefix():void
    {
        $request = (new Request())->setType('ouverture');
        self::assertSame('OUV-000', $request->getReference());
    }

    // Teste que la méthode getReference génère une référence correcte en fonction du type de demande (modification).
    public function testGetReferenceUsesModificationPrefix(): void
    {
        $request = (new Request())->setType('modification');
        self::assertSame('MOD-000', $request->getReference());
    }

    // Teste que la méthode getReference génère une référence correcte en fonction du type de demande (fermeture).
    public function testGetReferenceUsesFermeturePrefix(): void
    {
        $request = (new Request())->setType('fermeture');
        self::assertSame('FER-000', $request->getReference());
    }

    public function testGetReferenceUsesDefaultPrefix(): void
    {
        $request = (new Request())->setType('default');
        self::assertSame('REQ-000', $request->getReference());
    }

    // Teste le snapshot du workflow pour s'assurer qu'il est correctement stocké et récupéré.
    public function testSetAndGetWorkflowSnapshot(): void
    {
        $snapshot = [
            [
                'workflowCode' => 'default_access',
                'stepOrder' => 1,
                'action' => 'validate',
                'fromStatus' => 'en_attente_rh',
                'toStatus' => 'en_attente_st',
                'requiredRole' => 'ROLE_RH',
            ],
        ];

        $request = (new Request())->setWorkflowSnapshot($snapshot);
        self::assertSame($snapshot,$request->getWorkflowSnapshot());
    }

    // Teste que le workflowSnapshot peut être nul (ex: pour les demandes créées avant la mise en place du système de workflow).
    public function testSetWorkflowSnapshotCanBeNull(): void
    {
        $request = (new Request())->setWorkflowSnapshot(null);
        self::assertNull($request->getWorkflowSnapshot());
    }

    public function testSetAndGetCommmentaryCanBeNull(): void
    {
        $request = (new Request())->setCommentary(null);
        self::assertNull($request->getCommentary());
    }

    // Teste que la méthode getReference génère une référence correcte en fonction du type de demande.
    public function testAddChildRequestSetsParentBidirectionalRelation(): void
    {
        $parent = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');

        $child = (new Request())
            ->setType('modification')
            ->setStatus('traitee');

        $parent->addChildRequest($child);

        self::assertCount(1, $parent->getChildRequests());
        self::assertSame($parent, $child->getParentRequest());
    }

    // Teste que la suppression d'une childRequest de la parentRequest met à jour la relation inverse.
    public function testRemoveChildRequestUnsetsParentBidirectionalRelation(): void
    {
        $parent = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');
        
        $child = (new Request())
            ->setType('modification')
            ->setStatus('traitee');

        $parent->addChildRequest($child);
        $parent->removeChildRequest($child);

        self::assertCount(0, $parent->getChildRequests());
        self::assertNull($child->getParentRequest());
    }

    // teste que ca retourne true si le child est de type "modification" et traité
    public function testHasProcessedReplacementChildReturnsTrueForProcessedModification(): void
    {
        $parent = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');

        $child = (new Request())
            ->setType('modification')
            ->setStatus('traitee');

        $parent->addChildRequest($child);

        self::assertTrue($parent->hasProcessedReplacementChild());
    }

    // teste que ca retourne false si le child est de type "modification" mais pas traité
    public function testHasProcessedReplacementChildReturnsFalseForUnprocessedChild(): void
    {
        $parent = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');

        $child = (new Request())
            ->setType('modification')
            ->setStatus('en_attente_st');

        $parent->addChildRequest($child);

        self::assertFalse($parent->hasProcessedReplacementChild());
    }

    // teste que ca retourne false si le child est de type "fermeture" mais pas traité
    public function testIsCurrentStateReturnsFalseWhenReplaced(): void
    {
        $parent = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');

        $child = (new Request())
            ->setType('modification')
            ->setStatus('traitee');

        $parent->addChildRequest($child);

        self::assertFalse($parent->isCurrentState());
    }

    public function testGetCurrentStateLabelForReplacedRequest(): void
    {
        $parent = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');

        $child = (new Request())
            ->setType('modification')
            ->setStatus('traitee');

        $parent->addChildRequest($child);

        self::assertSame('Remplacée - Ouverture', $parent->getCurrentStateLabel());
    }

    // public function testGetCurrentStateLabelForReplacedRequestDefault(): void
    // {
    //     $parent = (new Request())
    //         ->setType('default');

    //     self::assertSame('demande', $parent->getType());
    // }

    public function testGetCurrentStateBadgeClassMappings(): void
    {
        $ouverture = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');

        self::assertSame('success', $ouverture->getCurrentStateBadgeClass());

        $modification = (new Request())
            ->setType('modification')
            ->setStatus('en_attente_st');

        self::assertSame('primary',$modification->getCurrentStateBadgeClass());

        $fermetureActive = (new Request())
            ->setType('fermeture')
            ->setStatus('en_attente_st');
        
        self::assertSame('dark', $fermetureActive->getCurrentStateBadgeClass());

        $fermetureCloturee = (new Request())
            ->setType('fermeture')
            ->setStatus('traitee');

        self::assertSame('dark', $fermetureCloturee->getCurrentStateBadgeClass());

        $replaced = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh');

        $child = (new Request())
            ->setType('modification')
            ->setStatus('traitee');

        $replaced->addChildRequest($child);

        self::assertSame('secondary', $replaced->getCurrentStateBadgeClass());
    }

}
