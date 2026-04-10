<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Service;
use App\Entity\User;
use App\Repository\AgentRepository;
use App\Repository\WorkflowTransitionConfigRepository;
use App\Service\RequestCreationService;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class NewRequestControllerTest extends WebTestCase
{
    public function testNewRequestRedirectsToLoginForAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/new/request');

        self::assertResponseRedirects('/login');
    }

    public function testNewRequestShowsDangerFlashWhenCreationServiceThrows(): void
    {
        self::markTestSkipped('Scenario non deterministe dans ce setup: RequestCreationService final autowire non surcharge de facon fiable en test fonctionnel.');

        $client = static::createClient();
        $container = static::getContainer();

        $emReal = $container->get(EntityManagerInterface::class);

        $serviceEntity = (new Service())
            ->setName('Service-ctrl-' . uniqid())
            ->setEmail('ctrl.' . uniqid() . '@mail.fr')
            ->setCode('rh');

        $user = (new User())
            ->setEmail('ctrl.user.' . uniqid() . '@mail.fr')
            ->setPassword('x')
            ->setIsActive(true)
            ->setService($serviceEntity);

        $emReal->persist($serviceEntity);
        $emReal->persist($user);
        $emReal->flush();

        $client->loginUser($user);

        $connectionMock = $this->createMock(Connection::class);
        $connectionMock
            ->method('beginTransaction')
            ->willThrowException(new \RuntimeException('Simulated failure'));

        $emMock = $this->createMock(EntityManagerInterface::class);
        $emMock->method('getConnection')->willReturn($connectionMock);

        $workflowRepo = $container->get(WorkflowTransitionConfigRepository::class);
        $agentRepo = $container->get(AgentRepository::class);

        $failingService = new RequestCreationService($emMock, $workflowRepo, $agentRepo);
        $container->set(RequestCreationService::class, $failingService);
        $container->set('test.' . RequestCreationService::class, $failingService);

        $crawler = $client->request('GET', '/new/request');

        $form = $crawler->filter('form.request-form')->form([
            'new_request[type]' => 'ouverture',
            'new_request[civility]' => 'M.',
            'new_request[firstname]' => 'Flash',
            'new_request[lastname]' => 'Error',
            'new_request[email]' => 'flash.error.' . uniqid() . '@mairie.fr',
            'new_request[service]' => (string) $serviceEntity->getId(),
            'new_request[jobTitle]' => 'Test',
            'new_request[arrivalDate]' => '2026-04-10',
            'new_request[commentary]' => 'test flash danger',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/new/request');
        $client->followRedirect();

        self::assertSelectorTextContains('.alert-danger', 'La création de la demande a échoué');
    }
}