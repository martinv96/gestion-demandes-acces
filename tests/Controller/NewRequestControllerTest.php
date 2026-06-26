<?php

declare(strict_types=1);

namespace App\Tests\Controller;

use App\Entity\Service;
use App\Entity\User;
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

    /**
     * Teste le comportement nominal du NewRequestController :
     * Un agent connecté peut remplir et soumettre le formulaire avec succès.
     */
    public function testNewRequestSuccessfullyCreatesRequest(): void
    {
        $client = static::createClient();
        $container = static::getContainer();

        $em = $container->get(EntityManagerInterface::class);

        // 1. Création d'un service et d'un utilisateur de test pour la session
        $serviceEntity = (new Service())
            ->setName('Service RH ' . uniqid())
            ->setEmail('rh.' . uniqid() . '@mail.fr')
            ->setCode('rh');

        $user = (new User())
            ->setEmail('agent.' . uniqid() . '@mail.fr')
            ->setFirstname('Martin')
            ->setLastname('Vallee')
            ->setPassword('motdepasse_test')
            ->setIsActive(true)
            ->setService($serviceEntity);

        $em->persist($serviceEntity);
        $em->persist($user);
        $em->flush();

        // Authentifier le client HTTP avec cet utilisateur
        $client->loginUser($user);

        // 2. Accès à la page du formulaire de création
        $crawler = $client->request('GET', '/new/request');
        self::assertResponseIsSuccessful(); // Le formulaire s'affiche bien (HTTP 200)

        // 3. Remplissage et soumission du formulaire avec des données valides
        $form = $crawler->filter('form.request-form')->form([
            'new_request[type]' => 'ouverture',
            'new_request[civility]' => 'M.',
            'new_request[firstname]' => 'Jean',
            'new_request[lastname]' => 'Dupont',
            'new_request[email]' => 'jean.dupont.' . uniqid() . '@mairie.fr',
            'new_request[service]' => (string) $serviceEntity->getId(), // L'ID valide généré au début
            'new_request[jobTitle]' => 'Adjoint administratif',
            'new_request[arrivalDate]' => '2026-09-01',
            'new_request[commentary]' => 'Création de compte standard via test fonctionnel.',
        ]);

        $client->submit($form);

        // 4. Assertions de fin
        // On s'assure que le contrôleur redirige bien vers l'URL de succès d'après ta logique (?saved=1)
        self::assertResponseRedirects('/new/request?saved=1');
        
        // On suit la redirection pour valider que la page finale répond correctement
        $client->followRedirect();
        self::assertResponseIsSuccessful();
    }
}