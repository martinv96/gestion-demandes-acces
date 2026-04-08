<?php
/*  
    declare(strict_types=1) pour activer le mode strict de PHP
    éviter les erreurs de type.
*/
declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Role;
use App\Entity\Service;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

// Vérifie les comportements métier de l'entité User:
// normalisation de l'email et construction des rôles.
final class UserTest extends TestCase
{
    // Teste que l'email est normalisé (trim et lowercase) lors de la définition.
    public function testSetEmailNormalizesValue(): void
    {
        $user = new User();
        $user->setEmail('  JOHN.DOE@MAIRIE.FR  ');

        self::assertSame('john.doe@mairie.fr', $user->getEmail());
    }

    // Teste que les rôles incluent le rôle utilisateur, le rôle de la base de données et le rôle du service.
    public function testGetRolesIncludesUserRoleAndDbRoleAndServiceRole(): void
    {
        $role = (new Role())->setLabel('admin');
        $service = (new Service())
            ->setName('Ressources Humaines')
            ->setEmail('rh@mairie.fr')
            ->setCode('rh');

        $user = (new User())
            ->setEmail('jane@mairie.fr')
            ->setPassword('x')
            ->setIsActive(true)
            ->setRole($role)
            ->setService($service);

        $roles = $user->getRoles();

        self::assertContains('ROLE_USER', $roles);
        self::assertContains('ROLE_ADMIN', $roles);
        self::assertContains('ROLE_RH', $roles);
    }

    // Teste que les rôles ne contiennent pas de doublons même si le rôle de la base de données est déjà ROLE_USER.
    public function testGetRolesDoesNotDuplicateEntries(): void
    {
        $role = (new Role())->setLabel('ROLE_USER');

        $user = (new User())
            ->setEmail('jane@mairie.fr')
            ->setPassword('x')
            ->setIsActive(true)
            ->setRole($role);

        $roles = $user->getRoles();

        self::assertSame(array_values(array_unique($roles)), $roles);
    }

    // Teste la construction du nom d'affichage en fonction des données disponibles.
    public function testGetDisplayNameFallsBackToEmailWhenNoService(): void
    {
        $user = (new User())
            ->setEmail('james.bond@mi6.co.uk');

        self::assertSame('james.bond@mi6.co.uk', $user->getDisplayName());
    }

    // Teste la construction du nom d'affichage en fonction des données disponibles.
    public function testGetDisplayNameFallbackToServiceName(): void
    {
        $service = (new Service())
            ->setName('DSI')
            ->setEmail('dsi@mairie.fr')
            ->setCode('dsi');
        
        $user = (new User())
            ->setEmail('dsi@mairie.fr')
            ->setService($service);

        self::assertSame('Compte DSI', $user->getDisplayName());
    }

    // Teste la construction du nom d'affichage en fonction des données disponibles.
    public function testGetDisplayNameFallbackToEmail(): void
    {
        $user = (new User())
            ->setEmail('fallback@mairie.fr');

        self::assertSame('fallback@mairie.fr', $user->getDisplayName());
    }


    // Teste la construction du nom d'affichage en fonction des données disponibles.
    public function testGetDisplayNameWithServiceIsCompteService(): void
    {
        $service = (new Service())
            ->setName('ST')
            ->setEmail('st@mairie.fr')
            ->setCode('st');

        $user = (new User())
            ->setEmail('st@mairie.fr')
            ->setService($service);

        self::assertSame('Compte ST', $user->getDisplayName());
    }
}
