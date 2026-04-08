<?php

declare(strict_types=1);

namespace App\Tests\Security;

use App\Entity\Request as AccessRequest;
use App\Entity\User;
use App\Security\Voter\RequestVoter;
use App\Service\WorkflowService;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\VoterInterface;


/*
    ? but global du test : 
    vérifier que le RequestVoter délègue correctement les décisions d'accès au WorkflowService
    et que les décisions sont cohérentes avec les retours du WorkflowService.    
*/
final class RequestVoterTest extends TestCase
{
/* 
   ? testValidateAttributeDelegatesToWorkflowService ()
    créer une demande et un user.
    configure un mock pour exiger un appel à canValidate avec la demande et l'utilisateur, et retourner true.
    appelle vote avec attribut VALIDATE et vérifie que le résultat est ACCESS_GRANTED.
*/
    public function testValidateAttributeDelegatesToWorkflowService(): void
    {
        $request = new AccessRequest();
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('x')
            ->setIsActive(true);

        // ! workflowService est mocké pour controler exactement ce qu'il renvoie
        $workflow = $this->createMock(WorkflowService::class);
        $workflow
            ->expects(self::once())
            ->method('canValidate')
            ->with($request, $user)
            ->willReturn(true);

        // ! mocké pour simulé un user connecté ou anonyme
        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $voter = new RequestVoter($workflow);

        $result = $voter->vote($token, $request, [RequestVoter::VALIDATE]);

        self::assertSame(VoterInterface::ACCESS_GRANTED, $result);
    }

    // ? testRefuseAttributeDeniedWhenWorkflowReturnsFalse()
    // ! meme structure que le test précédent, mais on vérifie que si canRefuse retourne false
    // ! alors le vote retourne ACCESS_DENIED.
    public function testRefuseAttributeDeniedWhenWorkflowReturnsFalse(): void
    {
        $request = new AccessRequest();
        $user = (new User())
            ->setEmail('user@example.com')
            ->setPassword('x')
            ->setIsActive(true);

        // ! canRefuse renvoie false.
        // ! vérifie que le voter refuse l'accès dans ce cas.
        $workflow = $this->createMock(WorkflowService::class);
        $workflow
            ->expects(self::once())
            ->method('canRefuse')
            ->willReturn(false);

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn($user);

        $voter = new RequestVoter($workflow);

        $result = $voter->vote($token, $request, [RequestVoter::REFUSE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }

    // ? testVoteDeniedWhenUserIsAnonymous()
    // ! vérifie que si l'utilisateur est anonyme (getUser() retourne null), alors le vote doit être ACCESS_DENIED
    // ! et que canValidate n'est jamais appelé dans ce cas.
    public function testVoteDeniedWhenUserIsAnonymous(): void
    {
        $workflow = $this->createMock(WorkflowService::class);
        $workflow->expects(self::never())->method('canValidate');

        $token = $this->createMock(TokenInterface::class);
        $token->method('getUser')->willReturn(null);

        $voter = new RequestVoter($workflow);

        $result = $voter->vote($token, new AccessRequest(), [RequestVoter::VALIDATE]);

        self::assertSame(VoterInterface::ACCESS_DENIED, $result);
    }
}
