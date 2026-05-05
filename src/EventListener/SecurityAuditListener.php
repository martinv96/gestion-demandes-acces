<?php

namespace App\EventListener;

use App\Entity\LoginAudit;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Http\Event\LoginFailureEvent;
use Symfony\Component\Security\Http\Event\LoginSuccessEvent;
use Symfony\Component\Security\Http\Event\LogoutEvent;

final class SecurityAuditListener
{
    public function __construct(
        private EntityManagerInterface $em,
        private ?LoggerInterface $logger = null,
    ) {
    }

    #[AsEventListener(event: LoginSuccessEvent::class)]
    public function onLoginSuccess(LoginSuccessEvent $event): void
    {
        $request = $event->getRequest();
        $user = $event->getUser();

        $auditUser = $user instanceof User ? $user : null;
        $email = $auditUser?->getEmail();

        if ($email === null || trim($email) === '') {
            $email = (string) $request->request->get('_username', '');
        }

        $this->store(
            LoginAudit::EVENT_SUCCESS,
            $email !== '' ? mb_strtolower(trim($email)) : null,
            $auditUser,
            $request->getClientIp(),
            (string) $request->headers->get('User-Agent', ''),
            method_exists($event, 'getFirewallName') ? $event->getFirewallName() : 'main',
            $this->buildSuccessDetail($auditUser)
        );
    }

    #[AsEventListener(event: LoginFailureEvent::class)]
    public function onLoginFailure(LoginFailureEvent $event): void
    {
        $request = $event->getRequest();
        $email = (string) $request->request->get('_username', '');

        $this->store(
            LoginAudit::EVENT_FAILURE,
            $email !== '' ? mb_strtolower(trim($email)) : null,
            null,
            $request->getClientIp(),
            (string) $request->headers->get('User-Agent', ''),
            method_exists($event, 'getFirewallName') ? $event->getFirewallName() : 'main',
            $this->buildFailureDetail($event->getException()->getMessageKey())
        );
    }

    #[AsEventListener(event: LogoutEvent::class)]
    public function onLogout(LogoutEvent $event): void
    {
        $request = $event->getRequest();
        $tokenUser = $event->getToken()?->getUser();

        $auditUser = $tokenUser instanceof User ? $tokenUser : null;
        $email = $auditUser?->getEmail();

        $this->store(
            LoginAudit::EVENT_LOGOUT,
            $email !== null ? mb_strtolower(trim($email)) : null,
            $auditUser,
            $request->getClientIp(),
            (string) $request->headers->get('User-Agent', ''),
            'main',
            $this->buildLogoutDetail($auditUser)
        );
    }

    private function store(
        string $eventType,
        ?string $email,
        ?User $user,
        ?string $ipAddress,
        ?string $userAgent,
        ?string $firewall,
        ?string $details
    ): void {
        try {
            $audit = new LoginAudit();
            $audit
                ->setEventType($eventType)
                ->setEmail($email)
                ->setUser($user)
                ->setIpAddress($ipAddress)
                ->setUserAgent($userAgent)
                ->setFirewall($firewall)
                ->setDetails($details);

            $this->em->persist($audit);
            $this->em->flush();
        } catch (\Throwable $e) {
            $this->logger?->error('Echec enregistrement audit connexion.', [
                'event_type' => $eventType,
                'email' => $email,
                'error' => $e->getMessage(),
            ]);
        }
    }

    private function buildSuccessDetail(?User $user): string
    {
        if ($user instanceof User) {
            return sprintf(
                'Connexion réussie pour %s (%s).',
                trim($user->getDisplayName()),
                (string) $user->getEmail()
            );
        }

        return 'Connexion réussie';
    }

    private function buildFailureDetail(string $messageKey): string
    {
        $normalized = mb_strtolower(trim($messageKey));

        $translations = [
            'the presented password is invalid'              => 'Mot de passe incorrect.',
            'the presented password cannot be empty'         => 'Le mot de passe ne peut pas être vide.',
            'invalid credentials'                            => 'Identifiants invalides.',
            'bad credentials'                                => 'Identifiants invalides.',
            'user not found'                                 => 'Utilisateur introuvable.',
            'username could not be found'                    => 'Utilisateur introuvable.',
            'account is disabled'                            => 'Compte désactivé.',
            'account is locked'                              => 'Compte verrouillé.',
            'account has expired'                            => 'Compte expiré.',
            'credentials have expired'                       => 'Identifiants expirés.',
            'too many failed login attempts'                 => 'Trop de tentatives de connexion.',
            'invalid csrf token'                             => 'Jeton CSRF invalide.',
            'no authentication provider found'              => 'Fournisseur d\'authentification introuvable.',
        ];

        foreach ($translations as $key => $label) {
            if (str_contains($normalized, $key)) {
                return 'Échec de connexion : ' . $label;
            }
        }

        return 'Échec de connexion.';
    }


    private function buildLogoutDetail(?User $user): string
    {
        if ($user instanceof User) {
            return sprintf('Déconnexion utilisateur : %s (%s).', trim($user->getDisplayName()),(string) $user->getEmail());
        }

        return 'Déconnexion utilisateur.';
    }



}