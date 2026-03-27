<?php

namespace App\EventListener;

use App\Entity\User;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Bundle\SecurityBundle\Security;

#[AsEventListener(event: KernelEvents::REQUEST, priority: -10)]
final class ForcePasswordChangeListener
{
    // Routes qu'on ne bloque jamais (login, logout, changement mdp lui-même, assets…)
    private const ALLOWED_ROUTES = [
        'app_login',
        'app_logout',
        'app_force_change_password',
    ];

    public function __construct(
        private Security $security,
        private RouterInterface $router,
    ) {}

    // ! methode qui écoute les requetes entrantes, vérifie si l'user doit changer le mdp et le redirige vers la page de changement mdp si necessaire
    public function __invoke(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        /** @var User|null $user */
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return;
        }

        if (!$user->isMustChangePassword()) {
            return;
        }

        $currentRoute = $request->attributes->get('_route');

        // Laisser passer les routes autorisées et les assets
        if (in_array($currentRoute, self::ALLOWED_ROUTES, true)) {
            return;
        }

        $event->setResponse(new RedirectResponse(
            $this->router->generate('app_force_change_password')
        ));
    }
}
