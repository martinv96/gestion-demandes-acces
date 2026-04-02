<?php

declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\Agent;
use App\Entity\Request;
use App\Entity\Role;
use App\Entity\Service;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use PHPUnit\Framework\TestCase;

final class WorkflowHistoryTest extends TestCase
{
    // Test de l'association avec Request 
    public function testSetAndGetStatusesAndCommentary(): void
    {
        $history = (new WorkflowHistory())
            ->setOldStatus('en_attente_rh')
            ->setNewStatus('en_attente_st')
            ->setCommentary('Validation RH OK');

        self::assertSame('en_attente_rh', $history->getOldStatus());
        self::assertSame('en_attente_st', $history->getNewStatus());
        self::assertSame('Validation RH OK', $history->getCommentary());
    }


}