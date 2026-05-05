<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Request;
use App\Entity\Ressource;
use PHPUnit\Framework\TestCase;

final class RessourceTest extends TestCase
{
    public function testSetAndGetName(): void
    {
        $ressource = (new Ressource())->setName('Imprimante HP Color');
        self::assertSame('Imprimante HP Color', $ressource->getName());
    }

    public function testSetAndGetCategory(): void
    {
        $ressource = (new Ressource())->setCategory('materiel');
        self::assertSame('materiel', $ressource->getCategory());
    }

    public function testDefaultAssignmentStatusIsNonAttribue(): void
    {
        $ressource = new Ressource();
        self::assertSame(Ressource::ASSIGNMENT_NON_ATTRIBUE, $ressource->getAssignmentStatus());
    }

    public function testSetAndGetIsActive(): void
    {
        $ressource = (new Ressource())->setIsActive(true);
        self::assertTrue($ressource->isActive());

        $ressource->setIsActive(false);
        self::assertFalse($ressource->isActive());
    }

    public function testSetAndGetAssignmentStatus(): void
    {
        $ressource = (new Ressource())->setAssignmentStatus(Ressource::ASSIGNMENT_ATTRIBUE);
        self::assertSame(Ressource::ASSIGNMENT_ATTRIBUE, $ressource->getAssignmentStatus());
    }

    public function testAssignmentStatusLabelsContainsBothValues(): void
    {
        $ressource = new Ressource();
        $labels = $ressource::getAssignmentStatusLabels();

        self::assertArrayHasKey(Ressource::ASSIGNMENT_ATTRIBUE, $labels);
        self::assertArrayHasKey(Ressource::ASSIGNMENT_NON_ATTRIBUE, $labels);
    }

    public function testRequestsCollectionIsInitialized(): void
    {
        $ressource = new Ressource();
        self::assertCount(0, $ressource->getRessourceRequest());
    }

    public function testAddAndRemoveRequest(): void
    {
        $ressource = new Ressource();
        $request = new Request();

        $ressource->addRessourceRequest($request);
        self::assertCount(1, $ressource->getRessourceRequest());

        $ressource->removeRessourceRequest($request);
        self::assertCount(0, $ressource->getRessourceRequest());
    }
}