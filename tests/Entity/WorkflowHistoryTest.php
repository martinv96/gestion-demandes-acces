<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Agent;
use App\Entity\Request;
use App\Entity\Role;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use PHPUnit\Framework\TestCase;

final class WorkflowHistoryTest extends TestCase
{
    // Test de l'association avec Request 
    public function testSetAndGetStatusesAndCommentary(): void
    {
        $history = (new WorkflowHistory())
            ->setOldStatus('en_attente_rh')
            ->setNewStatus('en_attente_st')
            ->setCommentary('Validation RH OK');

        self::assertSame('en_attente_rh', $history->getOldStatus());
        self::assertSame('en_attente_st', $history->getNewStatus());
        self::assertSame('Validation RH OK', $history->getCommentary());
    }


    // le test permet de tester la relation entre le WorkflowHistory et l'entité User
    public function testSetAndGetDate(): void
    {
        $date = new \DateTimeImmutable('2026-04-02 10:30:00');

        $history = (new WorkflowHistory())->setDate($date);

        self::assertSame($date, $history->getDate());
    }

    // le test permet de tester la relation entre le WorkflowHistory et l'entité User
    public function testSetAndGetRequestAndUserRelations(): void
    {
        $service = (new Service())
            ->setName('DSI')
            ->setEmail('dsi@mairie.fr')
            ->setCode('dsi');

        $role = (new Role())
            ->setLabel('ROLE_USER');

        $user = (new User())
            ->setEmail('compte.dsi@mairie.fr')
            ->setPassword('x')
            ->setIsActive(true)
            ->setRole($role)
            ->setService($service);

        $agent = (new Agent())
            ->setCivility('M')
            ->setFirstname('Jean')
            ->setLastname('Martin')
            ->setJobTitle('Technicien')
            ->setEmail('jean.martin@mairie.fr')
            ->setService($service);

        $request = (new Request())
            ->setType('ouverture')
            ->setStatus('en_attente_rh')
            ->setArrivalDate(new \DateTime('2026-04-10'))
            ->setCreationDate(new \DateTimeImmutable('2026-04-02 09:05:00'))
            ->setUpdateDate(new \DateTimeImmutable('2026-04-02 09:10:00'));

        $history = (new WorkflowHistory())
            ->setRequest($request)
            ->setUser($user);

        self::assertSame($request, $history->getRequest());
        self::assertSame($user, $history->getUser());       
    }

    // Teste que les méthodes setRequest et setUser acceptent null et que les getters retournent null après la désassociation.
    public function testSetRequestAndUserCanBeNull(): void
    {
        $history = new WorkflowHistory();

        $history->setRequest(null);
        $history->setUser(null);

        self::assertNull($history->getRequest());
        self::assertNull($history->getUser());
    }
}