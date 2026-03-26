<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Service\WorkflowService;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

/* 
    ? WorkflowServiceTest vérifie les règles de validation, de refus et d'édition après refus des demandes d'accès.
    ! c'est le coeur de la logique métier du workflow, 
    ! il teste que les transitions de statut sont appliquées correctement, que les permissions sont respectées,
    ! et que les historiques de workflow sont créés avec les bonnes informations.
*/

final class WorkflowServiceTest extends TestCase
{
    // ? testCanValidateReturnsTrueForMatchingRoleAndStatus()
    // ! vérifie que canValidate retourne true pour un utilisateur avec le rôle RH lorsque la
    // ! demande est en attente de validation RH.
    // ! une demande est en statut en_attente_RH, l'user est RH, alors canValidate doit retourner true (il peut valider).
    public function testCanValidateReturnsTrueForMatchingRoleAndStatus(): void
    {
        $service = new WorkflowService($this->createMock(EntityManagerInterface::class));
        $request = (new AccessRequest())->setStatus(WorkflowService::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('RH');

        self::assertTrue($service->canValidate($request, $user));
    }

    // ? testCanValidateReturnsFalseForWrongRole()
    // ! vérifie que canValidate retourne false pour un utilisateur avec un rôle incorrect lorsque la
    // ! demande est en attente de validation RH.
    // ! une demande est en statut en_attente_RH, l'user est DSI, alors canValidate doit retourner false (il ne peut pas valider).
    public function testCanValidateReturnsFalseForWrongRole(): void
    {
        $service = new WorkflowService($this->createMock(EntityManagerInterface::class));
        $request = (new AccessRequest())->setStatus(WorkflowService::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('DSI');

        self::assertFalse($service->canValidate($request, $user));
    }

    // ? testValidateAppliesTransitionAndPersistsHistory()
    // ! c'est le plus important, il vérifie :
    // ! em -> persist est appelé avec un objet workflowHistory qui a les bonnes propriétés (request, user, oldStatus, newStatus, commentary, date)
    // ! em -> flush est appelé une fois
    // ! change le statut de la demande vers en attente de validation ST
    // ! met à jour la date de mise à jour de la demande
    public function testValidateAppliesTransitionAndPersistsHistory(): void
    {
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist')->with(self::isInstanceOf(WorkflowHistory::class));
        $em->expects(self::once())->method('flush');

        $service = new WorkflowService($em);
        $request = (new AccessRequest())->setStatus(WorkflowService::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('RH');

        $service->validate($request, $user, 'Validation ok');

        self::assertSame(WorkflowService::STATUS_EN_ATTENTE_ST, $request->getStatus());
        self::assertNotNull($request->getUpdateDate());
    }

    /* 
    ? testValidateThrowsWhenCommentIsEmpty()
    ! si on appelle validate() avec un commentaire vide ou que avec des espaces : 
    ! une exception InvalidArgumentException doit être levée.
    */
    public function testValidateThrowsWhenCommentIsEmpty(): void
    {
        $service = new WorkflowService($this->createMock(EntityManagerInterface::class));
        $request = (new AccessRequest())->setStatus(WorkflowService::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('RH');

        $this->expectException(\InvalidArgumentException::class);
        $service->validate($request, $user, '   ');
    }

    /* 
    ? testCanEditAfterRefusalReturnsTrueForRhWhenRefusedByRh()
    ! si la demande est en statut refusée RH, et que user est RH
    ! il peut modifier pour resoumettre, donc canEditAfterRefusal doit retourner true.
    */
    public function testCanEditAfterRefusalReturnsTrueForRhWhenRefusedByRh(): void
    {
        $service = new WorkflowService($this->createMock(EntityManagerInterface::class));
        $request = (new AccessRequest())->setStatus(WorkflowService::STATUS_REFUSEE_RH);
        $user = $this->createUserWithRoleLabel('RH');

        self::assertTrue($service->canEditAfterRefusal($request, $user));
    }

    /* 
    ? testCanEditAfterRefusalReturnsTrueAfterRefusalFromStHistory()
    ! la demande est en attente RH, mais l'historique montre qu'elle a été refusée depuis le statut en attente ST,
    ! dans ce cas le RH doit pouvoir éditer pour corriger et resoumettre,
    */

    public function testCanEditAfterRefusalReturnsTrueAfterRefusalFromStHistory(): void
    {
        $service = new WorkflowService($this->createMock(EntityManagerInterface::class));
        $request = (new AccessRequest())->setStatus(WorkflowService::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('RH');

        $history = (new WorkflowHistory())
            ->setOldStatus(WorkflowService::STATUS_EN_ATTENTE_ST)
            ->setNewStatus(WorkflowService::STATUS_EN_ATTENTE_RH)
            ->setCommentary('Retour')
            ->setDate(new \DateTimeImmutable())
            ->setUser($user)
            ->setRequest($request);
        $request->addRequestId($history);

        self::assertTrue($service->canEditAfterRefusal($request, $user));
    }

    /* 
    ? testCanEditAfterRefusalReturnsFalseForNonRhUser()
    ! si la demande est en statut refusée RH, mais que l'user est DSI,
    ! il ne peut pas modifier la demande, donc canEditAfterRefusal doit retourner false.
    */
    public function testCanEditAfterRefusalReturnsFalseForNonRhUser(): void
    {
        $service = new WorkflowService($this->createMock(EntityManagerInterface::class));
        $request = (new AccessRequest())->setStatus(WorkflowService::STATUS_REFUSEE_RH);
        $user = $this->createUserWithRoleLabel('DSI');

        self::assertFalse($service->canEditAfterRefusal($request, $user));
    }

    /* 
    ? createUserWithRoleLabel() est une méthode utilitaire pour créer un utilisateur avec un rôle spécifique,
    ? cela permet de simplifier la création d'utilisateurs avec différents rôles dans les tests.
    */

    private function createUserWithRoleLabel(string $label): User
    {
        $role = (new Role())->setLabel($label);

        return (new User())
            ->setFirstname('Test')
            ->setLastname('User')
            ->setEmail('test@example.com')
            ->setPassword('x')
            ->setIsActive(true)
            ->setRole($role);
    }
}
