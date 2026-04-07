<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Role;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class RoleTest extends TestCase
{
    public function testSetAndGetLabel(): void
    {
        $role = (new Role())->setLabel('RH');
        self::assertSame('RH', $role->getLabel());
    }

    public function testUsersCollectionIsInitialized(): void
    {
        $role = new Role();
        self::assertCount(0, $role->getRoleId());
    }

    public function testAddAndRemoveUser(): void
    {
        $role = new Role();
        $user = new User();

        $role->addRoleId($user);
        self::assertCount(1, $role->getRoleId());
        self::assertSame($role, $user->getRole());

        $role->removeRoleId($user);
        self::assertCount(0, $role->getRoleId());
        self::assertNull($user->getRole());
    }
}