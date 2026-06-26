<?php

namespace App\Tests\Security;

use App\Entity\User;

use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final class UserLoginSecurityTest extends WebTestCase
{

    // Test pour vérifier qu'un utilisateur inactif ne peut pas se connecter
    public function testInactiveUserCannotLogin(): void
    {
        // Crée un utilisateur inactif pour le test
        $client = static::createClient();
        $container = static::getContainer();

        // Génère un email unique pour éviter les conflits avec d'autres tests
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);
        $email = 'inactive_' . uniqid() . '@example.com';
        $plainPassword = 'TestPassword123!';

        // Crée et persiste l'utilisateur inactif
        $user = (new User())
        ->setEmail($email)
        ->setIsActive(false)
        ->setFirstname('martin')
        ->setLastname('user')
        ->setMustChangePassword(false);
        $user->setPassword($hasher->hashPassword($user, $plainPassword));
        $em->persist($user);
        $em->flush();
        try {

            // Tente de se connecter avec l'utilisateur inactif
            $crawler = $client->request('GET', '/login');

            // Remplit et soumet le formulaire de connexion
            $form = $crawler->selectButton('Se connecter')->form(['_username' => $email, '_password' => $plainPassword,]);

            // Soumet le formulaire et vérifie que la redirection se fait vers la page de login avec un message d'erreur
            $client->submit($form);

            // Vérifie que l'utilisateur est redirigé vers la page de login et qu'un message d'erreur est affiché
            self::assertResponseRedirects('/login');

            // Suit la redirection et vérifie que le message d'erreur est présent
            $client->followRedirect();

            // Vérifie que la page de login est affichée et qu'un message d'erreur est présent
            self::assertResponseIsSuccessful();

            // Vérifie que l'utilisateur est toujours sur la page de login et qu'un message d'erreur est affiché
            $currentPath = $client->getRequest()->getPathInfo();

            // Vérifie que l'utilisateur est redirigé vers la page de login et qu'un message d'erreur est affiché
            self::assertSame('/login', $currentPath, 'Un compte inactif ne doit pas pouvoir se connecter et doit rester sur la page de login');

            // Vérifie que le message d'erreur est affiché
            self::assertSelectorExists('.alert.alert-danger', 'Un message d\'erreur doit être affiché pour un compte inactif');
        } finally {

            // Nettoie la base de données en supprimant l'utilisateur de test
            $managedUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            // Supprime l'utilisateur de test pour éviter les conflits avec d'autres tests
            if ($managedUser instanceof User) {
                $em->remove($managedUser);
                $em->flush();
            }
        }
    }

    // Test pour vérifier qu'un utilisateur actif peut se connecter
    public function testActiveUserCanLogin(): void
    {
        // Crée un utilisateur actif pour le test
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        // Génère un email unique pour éviter les conflits avec d'autres tests
        $email = 'active_' . uniqid() . '@example.com';
        $plainPassword = 'TestPassword123!';

        // Crée et persiste l'utilisateur actif
        $user = (new User())
            ->setEmail($email)
            ->setIsActive(true)
            ->setFirstname('martin')
            ->setLastname('user')
            ->setMustChangePassword(false);

        // Hash le mot de passe avant de le stocker
        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $em->persist($user);
        $em->flush();

        // Tente de se connecter avec l'utilisateur actif
        try {
            // Remplit et soumet le formulaire de connexion
            $crawler = $client->request('GET', '/login');
            $form = $crawler->selectButton('Se connecter')->form([
                '_username' => $email,
                '_password' => $plainPassword,
            ]);

            // Soumet le formulaire et vérifie que la redirection se fait vers la page d'accueil

            $client->submit($form);
            self::assertResponseRedirects('/');
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            $currentPath = $client->getRequest()->getPathInfo();
            self::assertEquals('/', $currentPath, 'Un compte actif doit pouvoir se connecter et être redirigé vers la page d\'accueil');

            // Vérifie que le nom de l'utilisateur est affiché dans la barre de navigation
        } finally {
            $managedUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);
            if ($managedUser instanceof User) {
                $em->remove($managedUser);
                $em->flush();
            }
        }
    }

    // Test pour vérifier qu'un utilisateur actif ne peut pas se connecter avec un mot de passe incorrect
    public function testLoginFailWithWrongPassword(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'wrongpass_' . uniqid() . '@example.com';
        $realPassword = 'TestPassword123!';
        $wrongPassword = 'WrongPassword123!';

        $user = (new User())
            ->setEmail($email)
            ->setIsActive(true)
            ->setFirstname('martin')
            ->setLastname('user')
            ->setMustChangePassword(false);

        $user->setPassword($hasher->hashPassword($user, $realPassword));

        $em->persist($user);
        $em->flush();

        // Tente de se connecter avec le mot de passe incorrect
        try {
            // Remplit et soumet le formulaire de connexion
            $crawler = $client->request('GET', '/login');
            $form = $crawler->selectButton('Se connecter')->form([
                '_username' => $email,
                '_password' => $wrongPassword,
            ]);

            // Soumet le formulaire et vérifie que la redirection se fait vers la page de login avec un message d'erreur
            $client->submit($form);

            // Vérifie que l'utilisateur est redirigé vers la page de login et qu'un message d'erreur est affiché
            self::assertResponseRedirects('/login');

            // Suit la redirection et vérifie que le message d'erreur est présent
            $client->followRedirect();
            self::assertResponseIsSuccessful();


            // Vérifie que l'utilisateur est toujours sur la page de login et qu'un message d'erreur est affiché
            $currentPath = $client->getRequest()->getPathInfo();
            self::assertSame('/login', $currentPath, 'Avec un mauvais mot de passe, on doit rester sur /login.');

            // Vérifie que le message d'erreur est affiché
            self::assertSelectorExists('.alert.alert-danger');
        } finally {
            // Nettoie la base de données en supprimant l'utilisateur de test
            $managedUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            // Supprimer l'utilisateur de test pour éviter les conflits avec d'autres tests
            if ($managedUser instanceof User) {
                $em->remove($managedUser);
                $em->flush();
            }
        }
    }

    // Test pour vérifier qu'un utilisateur actif avec l'obligation de changer son mot de passe est redirigé vers la page de changement de mot de passe
    public function testActiveUserWithMustChangePasswordIsRedirectedToChangePassword(): void
    {
        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'mustchange_' . uniqid() . '@example.com';
        $plainPassword = 'TempPassword123!';

        $user = (new User())
            ->setEmail($email)
            ->setIsActive(true)
            ->setFirstname('martin')
            ->setLastname('user')
            ->setMustChangePassword(true);

        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $em->persist($user);
        $em->flush();

        // Tente de se connecter avec l'utilisateur qui doit changer son mot de passe
        try {
            $crawler = $client->request('GET', '/login');
            $form = $crawler->selectButton('Se connecter')->form([
                '_username' => $email,
                '_password' => $plainPassword,
            ]);

            $client->submit($form);

            // 1) Login OK -> redirection vers la home
            self::assertResponseRedirects('/');

            // 2) La home force ensuite vers /change-password
            $client->followRedirect();
            self::assertResponseRedirects('/change-password');

            // 3) On suit et on vérifie la page finale
            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSame('/change-password', $client->getRequest()->getPathInfo());
        } finally {
            // Nettoie la base de données en supprimant l'utilisateur de test
            $managedUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            // Supprimer l'utilisateur de test pour éviter les conflits avec d'autres tests
            if ($managedUser instanceof User) {
                $em->remove($managedUser);
                $em->flush();
            }
        }
    }

    // Test pour vérifier qu'un utilisateur déjà authentifié est redirigé de la page de login vers la page d'accueil ou le tableau de bord
    public function testAuthenticatedUserIsRedirectedFromLoginToDashboard(): void
    {

        $client = static::createClient();
        $container = static::getContainer();
        $em = $container->get(EntityManagerInterface::class);
        $hasher = $container->get(UserPasswordHasherInterface::class);

        $email = 'alreadylogged_' . uniqid() . '@example.com';
        $plainPassword = 'TestPassword123!';
        $user = (new User())
            ->setEmail($email)
            ->setIsActive(true)
            ->setFirstname('martin')
            ->setLastname('user')
            ->setMustChangePassword(false);

        $user->setPassword($hasher->hashPassword($user, $plainPassword));

        $em->persist($user);
        $em->flush();

        // Tente de se connecter avec l'utilisateur actif et vérifie qu'il est redirigé de la page de login vers la page d'accueil
        try {
            // Se connecter pour authentifier l'utilisateur
            $crawler = $client->request('GET', '/login');
            $form = $crawler->selectButton('Se connecter')->form([
                '_username' => $email,
                '_password' => $plainPassword,
            ]);
            $client->submit($form);
            self::assertResponseRedirects('/');
            $client->followRedirect();
            self::assertResponseIsSuccessful();

            // Tente d'accéder à la page de login alors que l'utilisateur est déjà authentifié
            $client->request('GET', '/login');

            // Vérifie que l'utilisateur est redirigé vers la page d'accueil ou le tableau de bord
            self::assertResponseRedirects('/');

            $client->followRedirect();
            self::assertResponseIsSuccessful();
            self::assertSame('/', $client->getRequest()->getPathInfo());
        } finally {
            // Nettoie la base de données en supprimant l'utilisateur de test
            $managedUser = $em->getRepository(User::class)->findOneBy(['email' => $email]);

            // Supprimer l'utilisateur de test pour éviter les conflits avec d'autres tests
            if ($managedUser instanceof User) {
                $em->remove($managedUser);
                $em->flush();
            }
        }
    }
}
