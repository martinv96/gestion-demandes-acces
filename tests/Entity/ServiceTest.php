<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Service;
use App\Entity\User;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    public function testSetAndGetName(): void
    {
        $service = (new Service())->setName('DSI');
        self::assertSame('DSI', $service->getName());
    }

    public function testSetAndGetCode(): void
    {
        $service = (new Service())->setCode('DSI');
        self::assertSame('DSI', $service->getCode());
    }

    public function testSetCodeConvertEmptyStringToNull(): void
    {
        $service = (new Service())->setCode('');
        self::assertNull($service->getCode());
    }

    public function testSetAndGetEmail(): void
    {
        $service = (new Service())->setEmail('dsi@example.local');
        self::assertSame('dsi@example.local', $service->getEmail());
    }

    public function testUsersCollectionIsInitialized(): void
    {
        $service = new Service();
        self::assertCount(0, $service->getServiceId());
    }

    public function testAddAndRemoveUser(): void
    {
        $service = new Service();
        $user = new User();

        $service->addServiceId($user);
        self::assertCount(1, $service->getServiceId());
        self::assertSame($service, $user->getService());

        $service->removeServiceId($user);
        self::assertCount(0, $service->getServiceId());
        self::assertNull($user->getService());
    }
}