LoginFailureAlertService.php

<?php

namespace App\Service\Security;

use App\Entity\User;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

final class LoginFailureAlertService
{
    public function __construct(
        private MailerInterface $mailer,
        private UserRepository $userRepository,
        private string $mailerFrom = 'no-reply@localhost',
        private ?LoggerInterface $logger = null,
    ) {
    }

    public function sendFailedLoginAlert(
        ?string $attemptedEmail,
        ?string $ipAddress,
        ?string $userAgent,
        string $reason
    ): void {
        $attemptedEmail = $attemptedEmail !== null ? mb_strtolower(trim($attemptedEmail)) : null;
        $ipAddress = $ipAddress !== null ? trim($ipAddress) : null;
        $userAgent = $userAgent !== null ? trim($userAgent) : null;

        $recipients = $this->resolveAdminRecipients();

        if ($recipients === []) {
            $this->logger?->warning('Alerte échec connexion non envoyée: aucun admin actif trouvé.');
            return;
        }

        foreach ($recipients as $to) {
            try {
                $email = (new TemplatedEmail())
                    ->from($this->resolveMailerFrom())
                    ->to($to)
                    ->subject('Alerte sécurité - Tentative de connexion échouée')
                    ->htmlTemplate('emails/security_login_failure_alert.html.twig')
                    ->context([
                        'attempted_email' => $attemptedEmail ?: 'non renseigné',
                        'ip_address' => $ipAddress ?: 'non disponible',
                        'user_agent' => $userAgent ?: 'non disponible',
                        'reason' => $reason,
                        'occurred_at' => new \DateTimeImmutable(),
                    ]);

                $this->mailer->send($email);
            } catch (\Throwable $e) {
                $this->logger?->error('Échec envoi alerte tentative de connexion.', [
                    'to' => $to,
                    'attempted_email' => $attemptedEmail,
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function resolveAdminRecipients(): array
    {
        $users = $this->userRepository->findBy(['isActive' => true]);

        $emails = [];
        foreach ($users as $user) {
            if (!$user instanceof User) {
                continue;
            }

            $roles = $user->getRoles();
            if (!in_array('ROLE_ADMIN', $roles, true)) {
                continue;
            }

            $email = trim((string) $user->getEmail());
            if ($email === '') {
                continue;
            }

            $emails[] = mb_strtolower($email);
        }

        return array_values(array_unique($emails));
    }

    private function resolveMailerFrom(): string
    {
        $runtimeFrom = trim((string) ($_SERVER['MAILER_FROM'] ?? $_ENV['MAILER_FROM'] ?? ''));
        if ($runtimeFrom !== '') {
            return $runtimeFrom;
        }

        $configuredFrom = trim((string) $this->mailerFrom);

        return $configuredFrom !== '' ? $configuredFrom : 'no-reply@localhost';
    }
}

nouveau template dans templates/emails/security_login_failure_alert.html.twig:


{% apply inline_css %}
<style>
    body { margin: 0; padding: 0; background: #f7f8fa; font-family: Arial, sans-serif; color: #1f2937; }
    .wrap { max-width: 680px; margin: 0 auto; background: #ffffff; border: 1px solid #e5e7eb; }
    .head { background: #9a3412; color: #fff; padding: 22px 24px; }
    .head h1 { margin: 0; font-size: 20px; }
    .content { padding: 24px; }
    .line { margin: 0 0 12px; font-size: 14px; }
    .label { font-weight: 700; color: #374151; }
    .box { background: #f9fafb; border: 1px solid #e5e7eb; border-radius: 6px; padding: 14px; margin-top: 16px; }
    .foot { padding: 18px 24px; font-size: 12px; color: #6b7280; border-top: 1px solid #e5e7eb; }
</style>

<div class="wrap">
    <div class="head">
        <h1>Alerte sécurité: connexion échouée</h1>
    </div>

    <div class="content">
        <p class="line"><span class="label">Date:</span> {{ occurred_at|date('d/m/Y H:i:s') }}</p>
        <p class="line"><span class="label">Email saisi:</span> {{ attempted_email }}</p>
        <p class="line"><span class="label">Adresse IP:</span> {{ ip_address }}</p>
        <p class="line"><span class="label">Raison:</span> {{ reason }}</p>

        <div class="box">
            <p class="line"><span class="label">User-Agent:</span><br>{{ user_agent }}</p>
        </div>
    </div>

    <div class="foot">
        Message automatique envoyé par la plateforme GDAP.
    </div>
</div>
{% endapply %}

dans eventListener/Security/auditListener.php, ajouter en haut l'import:
use App\Service\Security\LoginFailureAlertService;

puis dans le constructeur:
public function __construct(
    private EntityManagerInterface $em,
    private ?LoggerInterface $logger = null,
    private ?LoginFailureAlertService $loginFailureAlertService = null,
) {
}

puis dans la méthode onLoginFailure, remplacer le bloc:
#[AsEventListener(event: LoginFailureEvent::class)]
public function onLoginFailure(LoginFailureEvent $event): void
{
    $request = $event->getRequest();
    $email = (string) $request->request->get('_username', '');
    $failureDetail = $this->buildFailureDetail($event->getException()->getMessageKey());

    $this->store(
        LoginAudit::EVENT_FAILURE,
        $email !== '' ? mb_strtolower(trim($email)) : null,
        null,
        $request->getClientIp(),
        (string) $request->headers->get('User-Agent', ''),
        method_exists($event, 'getFirewallName') ? $event->getFirewallName() : 'main',
        $failureDetail
    );

    $this->loginFailureAlertService?->sendFailedLoginAlert(
        $email !== '' ? $email : null,
        $request->getClientIp(),
        (string) $request->headers->get('User-Agent', ''),
        $failureDetail
    );
}


Pour les filtres:
<div class="text-center mt-4">
    <a href="/GuideUtilisateurGDAP.docx" download class="btn btn-outline-secondary btn-sm">
        <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" class="bi bi-download me-2" viewBox="0 0 16 16">
            <path d="M.5 9.9a.5.5 0 0 1 .5.5v2.5a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1v-2.5a.5.5 0 0 1 1 0v2.5a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2v-2.5a.5.5 0 0 1 .5-.5z"/>
            <path d="M7.646 11.854a.5.5 0 0 0 .708 0l3-3a.5.5 0 0 0-.708-.708L8.5 10.293V1.5a.5.5 0 0 0-1 0v8.793L5.354 8.146a.5.5 0 1 0-.708.708l3 3z"/>
        </svg>
        Guide utilisateur
    </a>
</div>

