<?php

declare(stric_types=1);

namespace App\Tests\Entity;

use App\Entity\Service;
use PHPUnit\Framework\TestCase;

final class ServiceTest extends TestCase
{
    public function testSetAndGetName(): void
    {
        $service = (new Service())
            ->setName('DSI');

        self::assertSame('DSI', $service->getName());
    }
    
}