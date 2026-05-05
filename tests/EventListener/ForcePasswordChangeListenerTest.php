<?php

declare(strict_types=1);

namespace App\Tests\EventListener;

use App\Entity\User;
use App\EventListener\ForcePasswordChangeListener;
use PHPUnit\Framework\TestCase;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Routing\RouterInterface;

final class ForcePasswordChangeListenerTest extends TestCase
{
    public function testDoesNothingWhenNotMainRequest(): void
    {
        $security = $this->createMock(Security::class);
        $router = $this->createMock(RouterInterface::class);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $security->method('getUser')->willReturn(null);

        $listener = new ForcePasswordChangeListener($security, $router);
        $event = new RequestEvent($kernel, new Request(), HttpKernelInterface::SUB_REQUEST);

        $listener($event);

        self::assertNull($event->getResponse());
    }

    public function testRedirectsWhenUserMustChangePasswordOnProtectedRoute(): void
    {
        $security = $this->createMock(Security::class);
        $router = $this->createMock(RouterInterface::class);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $user = (new User())
            ->setEmail('user@example.local')
            ->setPassword('hashed')
            ->setIsActive(true)
            ->setMustChangePassword(true);

        $security->method('getUser')->willReturn($user);
        $router->method('generate')->with('app_force_change_password')->willReturn('/force-change-password');

        $listener = new ForcePasswordChangeListener($security, $router);

        $request = new Request();
        $request->attributes->set('_route', 'app_dashboard');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        self::assertNotNull($event->getResponse());
        self::assertSame('/force-change-password', $event->getResponse()->headers->get('Location'));
    }

    public function testDoesNotRedirectOnAllowedRoute(): void
    {
        $security = $this->createMock(Security::class);
        $router = $this->createMock(RouterInterface::class);
        $kernel = $this->createMock(HttpKernelInterface::class);

        $user = (new User())
            ->setEmail('user@example.local')
            ->setPassword('hashed')
            ->setIsActive(true)
            ->setMustChangePassword(true);

        $security->method('getUser')->willReturn($user);

        $listener = new ForcePasswordChangeListener($security, $router);

        $request = new Request();
        $request->attributes->set('_route', 'app_login');
        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $listener($event);

        self::assertNull($event->getResponse());
    }
}
