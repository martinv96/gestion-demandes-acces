<?php

namespace App\Service\Contract;

use App\Repository\RequestRepository;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

final class ContractEndReminderService
{
    private array $thresholds = [ 30, 14, 7, 3, 1];

    public function __construct(
        private RequestRepository $resquestRepository,
        private UserRepository $userRepository,
        private MailerInterface $mailer,
        private ?LoggerInterface $logger = null,
    ) {}

    public function processUpcomingDepartures(): int
    {
        $now = new \DateTimeImmutable('today');
        $maxDays = max($this->thresholds);
        $to = $now->add(new \DateInterval(sprintf('P%dD', $maxDays)));

        $requests = $this->resquestRepository->findRequestsWithDepartureBetween($now, $to);

        if ($requests === []) {
            return 0;
        }

        $sent = 0;
        foreach ($requests as $request) {
            $departure = $request->getDepartureDate();
            if (!$departure instanceof \DateTimeInterface ) {
                continue;
            }

            $daysLeft = (int) $departure->diff($now)->format('%a');
            if (!in_array($daysLeft, $this->thresholds, true)) {
                continue;
            }

            //pour éviter d'envoyer plusieurs fois le même rappel
            $lastService = $request->getLastManualReminderService();
            if ($lastService === 'end_date_' . $daysLeft) {
                continue;
            }

            $agent = $request->getAgent();
            $recipients = [];

            if ($agent !== null && trim((string)$agent->getEmail()) !== '') {
                $recipients[] = trim((string)$agent->getEmail());
            }

            //pour l'admin et RH
            try {
                $activeUsers = $this->userRepository->findBy(['isActive' => true]);
            } catch (\Throwable $e) {
                $activeUsers = [];
            }

            foreach ($activeUsers as $user) {
                if (!is_object($user) || !method_exists($user, 'getRoles') || !method_exists($user, 'getEmail')) {
                    continue;
                }
                $roles = (array) $user->getRoles();
                if (in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_RH', $roles, true)) {
                    $email =trim((string)$user->getEmail());
                    if ($email !== '') {
                        $recipients[] = $email;
                    }
                }
            }

            $recipients = array_values(array_unique($recipients));
            if ($recipients === []) {
                continue;
            }

            $subject = sprintf('Rappel : fin de contrat dans %d jour(s) - %s', $daysLeft, $request->getReference());

            try {
                $email = (new TemplatedEmail())
                    ->from($_ENV['MAILER_FROM'] ?? 'no-reply@localhost')
                    ->to(...$recipients)
                    ->subject($subject)
                    ->htmlTemplate('emails/departure_reminder.html.twig')
                    ->context([
                        'request' => $request,
                        'days_left' => $daysLeft,
                    ]);

                $this->mailer->send($email);

                $request->setLastManualReminderAt(new \DateTimeImmutable());
                $request->setLastManualReminderService('end_date_' . $daysLeft);

                $this->logger?->info('Rappel fin contrat envoyé', [
                    'request_id' => $request->getId(),
                    'days_left' => $daysLeft,
                    'to' => $recipients,
                ]);

                $sent++;
            } catch (\Throwable $e) {
                $this->logger?->error('Erreur envoi rappel fin contrat', [
                    'request_id' => $request->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $sent;
    }
}