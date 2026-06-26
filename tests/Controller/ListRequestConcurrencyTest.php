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

final class ListRequestConcurrencyTest extends WebTestCase
{
    private const DEFAULT_PASSWORD = 'TestPassword123!';

    /**
     * Scénario 1 : Deux validations simultanées avec le même numéro de version.
     * La deuxième doit lever un conflit optimiste intercepté par le contrôleur.
     */
    public function testTwoValidationsWithSameVersionTriggersOptimisticConflict(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        [$user, $request] = $this->createRhUserAndRequest($em, $hasher, AccessRequest::STATUS_EN_ATTENTE_RH, AccessRequest::TYPE_OUVERTURE);

        $this->loginAs($client, $user);

        // L'utilisateur charge la page de détail de la demande
        $crawler = $client->request('GET', '/request/' . $request->getId());

        // Extraction sécurisée du token CSRF depuis le HTML de la page
        $csrfToken = $crawler->filter('input[name="_token"]')->count() > 0 
            ? $crawler->filter('input[name="_token"]')->attr('value')
            : $crawler->filter('input[name="workflow[_token]"]')->attr('value');

        // Première soumission (simule la première validation réussie)
        $client->request('POST', '/request/' . $request->getId() . '/validate', [
            '_token' => $csrfToken,
            'version' => $request->getVersion(), // Version initiale (1)
            'comment' => 'Validation 1',
        ]);
        self::assertResponseRedirects('/request/' . $request->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'La demande a été validée.');

        // Deuxième soumission concurrente avec la MÊME version initiale obsolète (1)
        $client->request('POST', '/request/' . $request->getId() . '/validate', [
            '_token' => $csrfToken,
            'version' => 1, // Version obsolète car la première soumission l'a incrémentée à 2
            'comment' => 'Validation 2 stale',
        ]);
        self::assertResponseRedirects('/request/' . $request->getId());
        $client->followRedirect();

        // Vérification du message flash réel de ton application
        self::assertSelectorTextContains('.alert-warning', 'Une action est déjà en cours. Recharger la page.');

        // On rafraîchit l'entité depuis la base pour tester la version finale
        $em->clear();
        $reloadedRequest = $em->getRepository(AccessRequest::class)->find($request->getId());

        self::assertInstanceOf(AccessRequest::class, $reloadedRequest);
        // La version a avancé d'un seul cran suite à la seule et unique première validation réussie
        self::assertSame(2, $reloadedRequest->getVersion());
    }

    /**
     * Scénario 2 : Soumission d'une version invalide ou absente.
     */
    public function testValidateWithMissingVersionTriggersError(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        [$user, $request] = $this->createRhUserAndRequest($em, $hasher, AccessRequest::STATUS_EN_ATTENTE_RH, AccessRequest::TYPE_OUVERTURE);

        $this->loginAs($client, $user);
        
        // Initialisation de la session et récupération du crawler
        $crawler = $client->request('GET', '/request/' . $request->getId());
        
        $csrfToken = $crawler->filter('input[name="_token"]')->count() > 0 
            ? $crawler->filter('input[name="_token"]')->attr('value')
            : $crawler->filter('input[name="workflow[_token]"]')->attr('value');

        // Envoi d'une version invalide (0)
        $client->request('POST', '/request/' . $request->getId() . '/validate', [
            '_token' => $csrfToken,
            'version' => 0,
            'comment' => 'Test sans version',
        ]);

        self::assertResponseRedirects('/request/' . $request->getId());
        $client->followRedirect();
        self::assertSelectorTextContains('.alert-danger', 'Version de la demande invalide.');
    }

    /**
     * Scénario 3 : Concurrence sur une demande de fermeture (Finalize Closure)
     */
    public function testTwoClosuresWithSameVersionTriggersOptimisticConflict(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        [$user, $request] = $this->createRhUserAndRequest($em, $hasher, AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT, AccessRequest::TYPE_FERMETURE);

        $this->loginAs($client, $user);
        
        // Initialisation de la session et récupération du crawler
        $crawler = $client->request('GET', '/request/' . $request->getId());
        
        $csrfToken = $crawler->filter('input[name="_token"]')->count() > 0 
            ? $crawler->filter('input[name="_token"]')->attr('value')
            : $crawler->filter('input[name="workflow[_token]"]')->attr('value');

        // Première clôture
        $client->request('POST', '/request/' . $request->getId() . '/validate', [
            '_token' => $csrfToken,
            'version' => $request->getVersion(),
            'comment' => 'Clôture finale 1',
        ]);
        self::assertResponseRedirects('/request/' . $request->getId());
        $client->followRedirect();

        // Deuxième clôture concurrente avec l'ancienne version
        $client->request('POST', '/request/' . $request->getId() . '/validate', [
            '_token' => $csrfToken,
            'version' => 1,
            'comment' => 'Clôture concurrente périmée',
        ]);
        self::assertResponseRedirects('/request/' . $request->getId());
        $client->followRedirect();

        // Vérification du message flash réel de ton application
        self::assertSelectorTextContains('.alert-warning', 'Une action est déjà en cours. Recharger la page.');
    }

    private function createRhUserAndRequest(EntityManagerInterface $em, UserPasswordHasherInterface $hasher, string $status, string $type): array
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
            ->setType($type)
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