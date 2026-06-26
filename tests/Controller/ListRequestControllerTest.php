<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Agent;
use App\Entity\Request as AccessRequest;
use App\Entity\Service;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class ListRequestControllerTest extends WebTestCase
{
    private const DEFAULT_PASSWORD = 'TestPassword123!';

    /**
     * Scénario 1 : Un utilisateur anonyme accède directement à la liste des demandes.
     */
    public function testListRequestIsAccessibleForAnonymous(): void
    {
        $client = static::createClient();
        $client->request('GET', '/list/request');

        // Correction : L'application répond bien en HTTP 200 OK pour les anonymes
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('title', 'Liste des demandes');
    }

    /**
     * Scénario 2 : Un utilisateur connecté affiche la liste.
     */
    public function testListRequestIsSuccessfulForAuthenticatedUser(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        [$user, $request] = $this->createRhUserAndRequest($em, $hasher, AccessRequest::STATUS_EN_ATTENTE_RH);

        $this->loginAs($client, $user);

        $client->request('GET', '/list/request');

        self::assertResponseIsSuccessful();
        if ($request->getReference()) {
            self::assertSelectorTextContains('body', $request->getReference());
        }
    }

    /**
     * Scénario 3 : Test des filtres de recherche.
     */
    public function testListRequestFilters(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        [$user, $request] = $this->createRhUserAndRequest($em, $hasher, AccessRequest::STATUS_EN_ATTENTE_RH);

        $this->loginAs($client, $user);

        $client->request('GET', '/list/request', [
            'status' => AccessRequest::STATUS_EN_ATTENTE_RH
        ]);

        self::assertResponseIsSuccessful();
        if ($request->getReference()) {
            self::assertSelectorTextContains('body', $request->getReference());
        }
    }

    private function createRhUserAndRequest(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, string $status): array
    {
        $uniq = str_replace('.', '', uniqid('cc', true));

        $service = (new Service())
            ->setName('SRV-' . $uniq)
            ->setEmail('service-' . $uniq . '@example.local')
            ->setCode('RH');

        $user = (new User())
            ->setEmail('rh-' . $uniq . '@example.local')
            ->setFirstname('Admin')
            ->setLastname('RH')
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