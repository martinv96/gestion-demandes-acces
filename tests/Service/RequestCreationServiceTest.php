<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Role;
use App\Entity\Service as UserService;
use App\Entity\User;
use App\Entity\Agent;
use App\Form\Model\NewRequestData;
use App\Repository\AgentRepository;
use App\Repository\RequestRepository;
use App\Repository\WorkflowHistoryRepository;
use App\Service\RequestCreationService;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class RequestCreationServiceTest extends KernelTestCase
{
    public function testRollbackIsCompleteWhenFailureOccursMidCreation(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $service = $container->get(RequestCreationService::class);

        $requestRepository = $container->get(RequestRepository::class);
        $agentRepository = $container->get(AgentRepository::class);
        $historyRepository = $container->get(WorkflowHistoryRepository::class);

        $uid = substr(uniqid('tx', true), 0, 12);

        $appService = (new UserService())
            ->setName('Service-' . $uid)
            ->setEmail($uid . '@mail.fr')
            ->setCode('rh');

        $role = (new Role())
            ->setLabel('ROLE_TX_' . strtoupper($uid));

        $user = (new User())
            ->setEmail($uid . '@creator.fr')
            ->setFirstname('martin')
            ->setLastname('user')
            ->setPassword('x')
            ->setIsActive(true)
            ->setRole($role)
            ->setService($appService);

        $em->persist($appService);
        $em->persist($role);
        $em->persist($user);
        $em->flush();

        $beforeRequestCount = $requestRepository->count([]);
        $beforeHistoryCount = $historyRepository->count([]);
        $beforeAgentCount = $agentRepository->count([]);

        $data = new NewRequestData();
        $data->setType('ouverture');
        $data->setCivility('M.');
        $data->setFirstname('Rollback');
        $data->setLastname('scenario');
        $data->setEmail('rollback@mairie.fr');
        $data->setJobTitle('test');
        $data->setService($appService);
        $data->setCommentary('test commentaire');
        $data->setArrivalDate(new \DateTime('2026-04-10'));

        $this->expectException(\RuntimeException::class);

        try {
            $service->createAtomically(
                $data,
                $user,
                'ouverture',
                'en_attente_rh',
                null,           // 5ème argument: $effectiveParentRequest (null ici)
                null,           // 6ème argument: $pieceJointeFile (null ici)
                static function (string $step): void { // 7ème argument: $failureHook
                    if ($step === 'after_history') {
                        throw new \RuntimeException('panne simulé après le persist intermédiaire');
                    }
                }
            );
        } finally {
            $em->clear();

            self::assertSame(
                $beforeRequestCount,
                $requestRepository->count([]),
                'Aucune request ne doit rester en base'
            );
            self::assertSame($beforeHistoryCount, $historyRepository->count([]), 'aucun history ne doit rester en base');
            self::assertSame($beforeAgentCount, $agentRepository->count([]), 'aucun agent intermédiaire ne doit rester en base');

            $agent = $agentRepository->findOneByIdentity('Rollback', 'scenario', 'rollback@mairie.fr');
            self::assertNull($agent, 'agent créé dans la transaction doit être rollbacké');
        }
    }

    public function testCreationPersistsAllEntities(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $service = $container->get(RequestCreationService::class);

        $requestRepository = $container->get(RequestRepository::class);
        $agentRepository = $container->get(AgentRepository::class);
        $historyRepository = $container->get(WorkflowHistoryRepository::class);

        $uid = substr(uniqid('ok', true), 0, 12);

        $appService = (new UserService())
            ->setName('ServiceOk-' . $uid)
            ->setEmail($uid . '@mail.fr')
            ->setCode('rh');

        $role = (new Role())
            ->setLabel('ROLE_OK_' . strtoupper($uid));

        $user = (new User())
            ->setEmail($uid . '@creator.fr')
            ->setFirstname('martin')
            ->setLastname('user')
            ->setPassword('x')
            ->setIsActive(true)
            ->setRole($role)
            ->setService($appService);

        $em->persist($appService);
        $em->persist($role);
        $em->persist($user);
        $em->flush();

        $beforeRequestCount = $requestRepository->count([]);
        $beforeHistoryCount = $historyRepository->count([]);
        $beforeAgentCount = $agentRepository->count([]);

        $data = new NewRequestData();
        $data->setType('ouverture');
        $data->setCivility('M.');
        $data->setFirstname('Jean');
        $data->setLastname('Dupont');
        $data->setEmail('jean.dupont.' . $uid . '@mairie.fr');
        $data->setJobTitle('Dev');
        $data->setService($appService);
        $data->setCommentary('test nominal');
        $data->setArrivalDate(new \DateTime('2026-04-10'));

        $created = $service->createAtomically(
            $data,
            $user,
            'ouverture',
            'en_attente_rh',
            null
        );

        self::assertNotNull($created->getId());

        $em->clear();

        self::assertSame($beforeRequestCount + 1, $requestRepository->count([]));
        self::assertSame($beforeHistoryCount + 1, $historyRepository->count([]));
        self::assertSame($beforeAgentCount + 1, $agentRepository->count([]));
    }

    public function testCreationReusesExistingAgentIdentity(): void
    {
        self::bootKernel();

        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $service = $container->get(RequestCreationService::class);

        $requestRepository = $container->get(RequestRepository::class);
        $agentRepository = $container->get(AgentRepository::class);
        $historyRepository = $container->get(WorkflowHistoryRepository::class);

        $uid = substr(uniqid('reuse', true), 0, 12);

        $appService = (new UserService())
            ->setName('ServiceReuse-' . $uid)
            ->setEmail($uid . '@mail.fr')
            ->setCode('rh');

        $role = (new Role())
            ->setLabel('ROLE_REUSE_' . strtoupper($uid));

        $user = (new User())
            ->setEmail($uid . '@creator.fr')
            ->setPassword('password')
            ->setFirstname('martin')
            ->setLastname('user')
            ->setIsActive(true)
            ->setRole($role)
            ->setService($appService);

        $existingAgent = (new Agent())
            ->setCivility('M.')
            ->setFirstname('Reuse')
            ->setLastname('Agent')
            ->setJobTitle('Avant')
            ->setEmail('reuse.agent@mairie.fr')
            ->setService($appService);

        $em->persist($appService);
        $em->persist($role);
        $em->persist($user);
        $em->persist($existingAgent);
        $em->flush();

        $existingAgentId = $existingAgent->getId();

        $beforeRequestCount = $requestRepository->count([]);
        $beforeHistoryCount = $historyRepository->count([]);
        $beforeAgentCount = $agentRepository->count([]);

        $data = new NewRequestData();
        $data->setType('ouverture');
        $data->setCivility('M.');
        $identityFirstname = 'Reuse' . $uid;
        $identityLastname = 'Agent' . $uid;
        $identityEmail = 'reuse.agent.' . $uid . '@mairie.fr';

        $existingAgent
            ->setFirstname($identityFirstname)
            ->setLastname($identityLastname)
            ->setEmail($identityEmail);
        $em->flush();

        $data->setFirstname($identityFirstname);
        $data->setLastname($identityLastname);
        $data->setEmail($identityEmail);
        $data->setJobTitle('Apres');
        $data->setService($appService);
        $data->setCommentary('test reutilisation agent');
        $data->setArrivalDate(new \DateTime('2026-04-10'));

        $created = $service->createAtomically(
            $data,
            $user,
            'ouverture',
            'en_attente_rh',
            null
        );

        $em->clear();

        self::assertSame($beforeRequestCount + 1, $requestRepository->count([]));
        self::assertSame($beforeHistoryCount + 1, $historyRepository->count([]));
        self::assertSame($beforeAgentCount, $agentRepository->count([]), 'Aucun nouvel agent ne doit être cree.');

        $reused = $agentRepository->findOneByIdentity($identityFirstname, $identityLastname, $identityEmail);
        self::assertNotNull($reused);
        self::assertSame($existingAgentId, $reused->getId(), 'La demande doit réutiliser l agent existant');

        $requestFromDb = $requestRepository->find($created->getId());
        self::assertNotNull($requestFromDb);
        self::assertSame($existingAgentId, $requestFromDb->getAgent()?->getId());
    }
}
