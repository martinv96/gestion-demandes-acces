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

    /**
     * @return list<string>
     */
    private function resolveAdminRecipients(): array
    {
        $activeUsers = $this->userRepository->findBy(['isActive' => true]);

        $emails = [];

        foreach ($activeUsers as $user) {
            if (!$user instanceof User) {
                continue;
            }

            if (!in_array('ROLE_ADMIN', $user->getRoles(), true)) {
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