<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Agent;
use App\Entity\Request as AccessRequest;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ListRequestConcurrencyTest extends WebTestCase
{
    public function testTwoValidationsWithSameVersionTriggersOptimisticConflict(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        [$user, $request] = $this->createRhUserAndRequest($em, $hasher, AccessRequest::STATUS_EN_ATTENTE_RH);

        $this->loginAs($client, $user);

        $crawler = $client->request('GET', '/request/' . $request->getId());
        $validateForm = $crawler->selectButton('Confirmer la validation')->form([
            'comment' => 'Validation 1',
        ]);

        $client->submit($validateForm);
        self::assertResponseRedirects();

        $staleValidateForm = $crawler->selectButton('Confirmer la validation')->form([
            'comment' => 'Validation 2 stale',
        ]);

        $client->submit($staleValidateForm);
        self::assertResponseRedirects();

        $em->clear();
        $reloadedRequest = $em->getRepository(AccessRequest::class)->find($request->getId());

        self::assertInstanceOf(AccessRequest::class, $reloadedRequest);
        self::assertSame(AccessRequest::STATUS_EN_ATTENTE_VALIDATION, $reloadedRequest->getStatus());
        self::assertSame(2, $reloadedRequest->getVersion());
        self::assertSame(1, $em->getRepository(WorkflowHistory::class)->count(['request' => $reloadedRequest]));
    }

    public function testUpdateInfoThenValidateWithOldVersionTriggersConflict(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        [$user, $request] = $this->createRhUserAndRequest($em, $hasher, AccessRequest::STATUS_REFUSEE_DSI);

        $this->loginAs($client, $user);

        $crawler = $client->request('GET', '/request/' . $request->getId());
        $editForm = $crawler->selectButton('Enregistrer les modifications')->form([
            'commentaire' => 'Maj RH',
        ]);
        $staleValidateForm = $crawler->selectButton('Confirmer la validation')->form([
            'comment' => 'Validation stale',
        ]);

        $client->submit($editForm);
        self::assertResponseRedirects();

        $client->submit($staleValidateForm);
        self::assertResponseRedirects();

        $em->clear();
        $reloadedRequest = $em->getRepository(AccessRequest::class)->find($request->getId());

        self::assertInstanceOf(AccessRequest::class, $reloadedRequest);
        self::assertSame(AccessRequest::STATUS_REFUSEE_DSI, $reloadedRequest->getStatus());
        self::assertSame(2, $reloadedRequest->getVersion());
        self::assertStringContainsString('Maj RH', (string) $reloadedRequest->getCommentary());
        self::assertSame(0, $em->getRepository(WorkflowHistory::class)->count(['request' => $reloadedRequest]));
    }

    private const DEFAULT_PASSWORD = 'TestPassword123!';

    private function createRhUserAndRequest(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, string $status): array
    {
        $uniq = str_replace('.', '', uniqid('cc', true));

        $service = (new Service())
            ->setName('SRV-' . $uniq)
            ->setEmail('service-' . $uniq . '@example.local')
            ->setCode('RH');

        $user = (new User())
            ->setEmail('rh-' . $uniq . '@example.local')
            ->setIsActive(true)
            ->setMustChangePassword(false)
            ->setService($service);

        $user->setPassword($hasher->hashPassword($user, self::DEFAULT_PASSWORD));

        $agent = (new Agent())
            ->setCivility('M.')
            ->setFirstname('Agent' . $uniq)
            ->setLastname('Test')
            ->setJobTitle('Tech')
            ->setEmail('agent-' . $uniq . '@example.local')
            ->setService($service);

        $request = (new AccessRequest())
            ->setType(AccessRequest::TYPE_OUVERTURE)
            ->setStatus($status)
            ->setAgent($agent)
            ->setAuthor($user)
            ->setArrivalDate(new \DateTime('2026-04-08'))
            ->setCommentary('Initial')
            ->setCreationDate(new \DateTimeImmutable('now'))
            ->setUpdateDate(new \DateTimeImmutable('now'));

        $em->persist($service);
        $em->persist($user);
        $em->persist($agent);
        $em->persist($request);
        $em->flush();

        return [$user, $request];
    }

    private function loginAs($client, User $user): void
    {
        $crawler = $client->request('GET', '/login');
        $form = $crawler->selectButton('Se connecter')->form([
            '_username' => (string) $user->getEmail(),
            '_password' => self::DEFAULT_PASSWORD,
        ]);

        $client->submit($form);
        self::assertResponseRedirects('/');
    }
}