<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Agent;
use App\Entity\Service;
use PHPUnit\Framework\TestCase;

final class AgentTest extends TestCase
{
    public function testSetAndGetCivility(): void
    {
        $agent = (new Agent())->setCivility('M.');
        self::assertSame('M.', $agent->getCivility());
    }

    public function testSetAndGetFirstnameLastname(): void
    {
        $agent = (new Agent())
            ->setFirstname('James')
            ->setLastname('Bond');

        self::assertSame('James', $agent->getFirstname());
        self::assertSame('Bond', $agent->getLastname());  
    }

    public function testEmailIsNullableByDefault(): void
    {
        $agent = new Agent();
        self::assertNull($agent->getEmail());
    }

    public function testSetAndGetEmail(): void
    {
        $agent =(new Agent())->setEmail('james.bond@example.com');
        self::assertSame('james.bond@example.com', $agent->getEmail());

    }

    public function testSetAndGetJobTitle(): void
    {
        $agent = (new Agent())->setJobTitle('Directeur');
        self::assertSame('Directeur', $agent->getJobTitle());
    }

    public function testServiceAssociation(): void
    {
        $service = (new Service())
            ->setName('DSI')
            ->setEmail('dsi@mairie.fr')
            ->setCode('dsi');

        $agent = (new Agent())->setService($service);

        self::assertSame($service, $agent->getService());
    }

    public function testAgentIdCollectionIsInitializedEmpty(): void
    {
        $agent = new Agent();
        self::assertCount(0, $agent->getAgentId());
    }
}