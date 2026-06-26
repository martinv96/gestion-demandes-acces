<?php

declare(strict_types=1);

namespace App\Tests\Service;

use App\Entity\Request as AccessRequest;
use App\Entity\Role;
use App\Entity\User;
use App\Entity\WorkflowHistory;
use App\Service\WorkflowService;
use App\Service\Workflow\WorkflowActionService;
use App\Service\Workflow\WorkflowNotificationService;
use App\Service\Workflow\WorkflowPermissionChecker;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class WorkflowServiceTest extends TestCase
{
    public function testCanValidateReturnsTrueForMatchingRoleAndStatus(): void
    {
        $checker = $this->createMock(WorkflowPermissionChecker::class);
        $checker->method('canValidate')->willReturn(true);

        $service = $this->createWorkflowService(null, $checker);
        $request = (new AccessRequest())->setStatus(AccessRequest::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('RH');

        self::assertTrue($service->canValidate($request, $user));
    }

    public function testCanValidateReturnsFalseForWrongRole(): void
    {
        $checker = $this->createMock(WorkflowPermissionChecker::class);
        $checker->method('canValidate')->willReturn(false);

        $service = $this->createWorkflowService(null, $checker);
        $request = (new AccessRequest())->setStatus(AccessRequest::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('DSI');

        self::assertFalse($service->canValidate($request, $user));
    }

    public function testValidateAppliesTransition(): void
    {
        $request = (new AccessRequest())->setStatus(AccessRequest::STATUS_EN_ATTENTE_RH)->setType(AccessRequest::TYPE_OUVERTURE);
        $user = $this->createUserWithRoleLabel('RH');
        $comment = 'Validation ok';

        $actionService = $this->createMock(WorkflowActionService::class);
        $actionService->expects(self::once())
            ->method('validate')
            ->with($request, $user, $comment, null)
            ->willReturnCallback(function($req) {
                $req->setStatus(AccessRequest::STATUS_EN_ATTENTE_VALIDATION);
                $req->setUpdateDate(new \DateTimeImmutable());
            });

        $service = $this->createWorkflowService($actionService);
        $service->validate($request, $user, $comment);

        self::assertSame(AccessRequest::STATUS_EN_ATTENTE_VALIDATION, $request->getStatus());
        self::assertNotNull($request->getUpdateDate());
    }

    public function testValidateThrowsWhenCommentIsEmpty(): void
    {
        $actionService = $this->createMock(WorkflowActionService::class);
        $actionService->method('validate')->willThrowException(new \InvalidArgumentException());

        $service = $this->createWorkflowService($actionService);
        $request = (new AccessRequest())->setStatus(AccessRequest::STATUS_EN_ATTENTE_RH);
        $user = $this->createUserWithRoleLabel('RH');

        $this->expectException(\InvalidArgumentException::class);
        $service->validate($request, $user, '   ');
    }

    public function testCanEditAfterRefusalReturnsTrueForRhWhenRefusedByRh(): void
    {
        $checker = $this->createMock(WorkflowPermissionChecker::class);
        $checker->method('canEditAfterRefusal')->willReturn(true);

        $service = $this->createWorkflowService(null, $checker);
        $request = (new AccessRequest())->setStatus('refusee_rh');
        $user = $this->createUserWithRoleLabel('RH');

        self::assertTrue($service->canEditAfterRefusal($request, $user));
    }

    public function testRefuseFromDsiSetsRefusedDsiStatus(): void
    {
        $request = (new AccessRequest())->setStatus(AccessRequest::STATUS_EN_ATTENTE_DSI);
        $user = $this->createUserWithRoleLabel('DSI');

        $actionService = $this->createMock(WorkflowActionService::class);
        $actionService->expects(self::once())
            ->method('refuse')
            ->willReturnCallback(function($req) {
                $req->setStatus('refusee_dsi');
            });

        $service = $this->createWorkflowService($actionService);
        $service->refuse($request, $user, 'Refus DSI');

        self::assertSame('refusee_dsi', $request->getStatus());
    }

    private function createWorkflowService(
        ?WorkflowActionService $actionService = null,
        ?WorkflowPermissionChecker $permissionChecker = null,
        ?WorkflowNotificationService $notificationService = null,
        ?MessageBusInterface $messageBus = null
    ): WorkflowService {
        return new WorkflowService(
            $actionService ?? $this->createMock(WorkflowActionService::class),
            $permissionChecker ?? $this->createMock(WorkflowPermissionChecker::class),
            $notificationService ?? $this->createMock(WorkflowNotificationService::class),
            $messageBus ?? $this->createMock(MessageBusInterface::class)
        );
    }

    private function createUserWithRoleLabel(string $label): User
    {
        $role = (new Role())->setLabel($label);
        return (new User())
            ->setEmail('test@example.com')
            ->setPassword('x')
            ->setIsActive(true)
            ->setRole($role);
    }
}