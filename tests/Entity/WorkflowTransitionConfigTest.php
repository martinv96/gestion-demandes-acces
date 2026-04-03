<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\WorkflowTransitionConfig;
use PHPUnit\Framework\TestCase;

final class WorkflowTransitionConfigTest extends TestCase
{
    public function testSetAndGetAllMainFields(): void
    {
        $config =(new WorkflowTransitionConfig())
            ->setWorkflowCode('default_access')
            ->setStepOrder(3)
            ->setAction('validate')
            ->setFromStatus('en_attente_dsi')
            ->setToStatus('traitee')
            ->setRequiredRole('ROLE_DSI')
            ->setIsActive(true);

        self::assertSame('default_access', $config->getWorkflowCode());
        self::assertSame(3, $config->getStepOrder());
        self::assertSame('validate', $config->getAction());
        self::assertSame('en_attente_dsi', $config->getFromStatus());
        self::assertSame('traitee', $config->getToStatus());
        self::assertSame('ROLE_DSI', $config->getRequiredRole());
        self::assertTrue($config->isActive());
    }


    // Ce test vérifie que la méthode isActive() retourne true par défaut lorsque la valeur de isActive est null, conformément à la logique définie dans l'entité WorkflowTransitionConfig.
    public function testIsActiveReturnsTrueByDefaultWhenValueIsNull(): void
    {
        $config = new WorkflowTransitionConfig();

        self::assertTrue($config->isActive());
    }

    // Ce test vérifie que la méthode isActive() retourne false lorsque la valeur de isActive est explicitement définie sur false, conformément à la logique définie dans l'entité WorkflowTransitionConfig.
    public function testSetIsActiveFalse(): void
    {
        $config = (new WorkflowTransitionConfig())->setIsActive(false);

        self::assertFalse($config->isActive());
    }
}