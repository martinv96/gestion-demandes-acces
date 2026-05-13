<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Repository\UserRepository;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Twig\Mime\TemplatedEmail;
use Symfony\Component\Mailer\MailerInterface;

class WorkflowNotificationService
{
    /**
     * @var array<string, string>
     */
    private const LABELS = [
        AccessRequest::STATUS_EN_ATTENTE_RH => 'En attente RH',
        AccessRequest::STATUS_EN_ATTENTE_VALIDATION => 'En attente de validations des services',
        AccessRequest::STATUS_EN_ATTENTE_ST => 'En attente ST',
        AccessRequest::STATUS_EN_ATTENTE_DSI => 'En attente DSI',
        AccessRequest::STATUS_EN_ATTENTE_TRAITEMENT => 'En attente de traitement',
        AccessRequest::STATUS_TRAITEE => 'Traitée',
        'refusee_rh' => 'Refusée par RH',
        'refusee_st' => 'Refusée par ST',
        'refusee_dsi' => 'Refusée par DSI',
    ];

    public function __construct(
        private MailerInterface $mailer,
        private UserRepository $userRepository,
        private WorkflowStateResolver $stateResolver,
        private string $mailerFrom = 'no-reply@localhost',
        private ?LoggerInterface $logger = null
    ) {
    }

    public function notifyAllActors(AccessRequest $request, string $comment): void
    {
        try {
            $activeUsers = $this->userRepository->findBy(['isActive' => true]);
        } catch (\Throwable) {
            return;
        }

        if (!is_array($activeUsers) || $activeUsers === []) {
            return;
        }

        $nextRoles = $this->stateResolver->getNextValidatorRoles($request);

        foreach ($activeUsers as $user) {
            if (!is_object($user) || !method_exists($user, 'getEmail') || !method_exists($user, 'getRoles')) {
                continue;
            }

            $emailAddress = trim((string) $user->getEmail());
            if ($emailAddress === '') {
                continue;
            }

            $isNextValidator = !empty(array_intersect($nextRoles, $user->getRoles()));

            try {
                $this->sendEmail($emailAddress, $request, $comment, $isNextValidator);
                $this->logInfo($emailAddress, $request, $isNextValidator);
            } catch (\Throwable $e) {
                $this->logError("Echec envoi mail workflow", $request, $e, $emailAddress, $isNextValidator);
            }
        }
    }

    public function sendReminder (AccessRequest $request, string $message = 'Ceci est un rappel automatique.'): void
    {
        try {
            $nextRoles = $this->stateResolver->getNextValidatorRoles($request);
            $activeUsers = $this->userRepository->findBy(['isActive' => true]);
        } catch (\Throwable) {
            return;
        }

        foreach ($activeUsers as $user) {
            if (!is_object($user) || !method_exists($user, 'getEmail') || !method_exists($user, 'getRoles')) {
                continue;
            }
            $emailAddress = trim((string) $user->getEmail());
            if ($emailAddress === '') {
                continue;
            }
            $isNextValidator = !empty(array_intersect($nextRoles, $user->getRoles()));
            if ($isNextValidator) {
                try {
                    $email = (new TemplatedEmail())
                        ->from($this->resolveMailerFrom())
                        ->to($emailAddress)
                        ->subject($this->buildSubject('RAPPEL', $request))
                        ->htmlTemplate('emails/notification_reminder.html.twig')
                        ->context([
                            'request' => $request,
                            'status_label' => $this->getLabel((string) $request->getStatus()), 'reminder_message' => $message,
                        ]);
                    $this->mailer->send($email);
                    $this->logInfo($emailAddress, $request, true);
                } catch (\Throwable $e) {
                    $this->logError("Echec de l'envoi du mail de rappel", $request, $e, $emailAddress, true);
                }
            }
        }
    }

    public function sendEscalation(AccessRequest $request, string $message ='La demande est toujours en attente et nécessite une intervention.'): void
    {
        try {
            $activeUsers = $this->userRepository->findBy(['isActive' => true]);
        } catch (\Throwable) {
            return;
        }

        foreach ($activeUsers as $user) {
            if (!is_object($user)|| !method_exists($user,'getEmail') || !method_exists($user,'getRoles')) {
                continue;
            }

            $roles = (array) $user->getRoles();
            $mustReceiveEscalation = in_array('ROLE_ADMIN',$roles, true) || in_array('ROLE_RH',$roles, true);

            if(!$mustReceiveEscalation) {
                continue;
            }

            $emailAddress = trim((string) $user->getEmail());
            if ($emailAddress === '') {
                continue;
            }

            try {
                $email = (new TemplatedEmail())
                    ->from($this->resolveMailerFrom())
                    ->to($emailAddress)
                    ->subject($this->buildSubject('ESCALADE', $request))
                    ->htmlTemplate('emails/notification_info.html.twig')
                    ->context([
                        'request' => $request,
                        'status_label' => $this->getLabel((string) $request->getStatus()), 'last_comment' => $message,
                    ]);

                $this->mailer->send($email);

                $this->logger?->info('Escalade workflow envoyée.',[
                    'request_id' => $request->getId(),
                    'to' => $emailAddress,
                    'status' => $request->getStatus(),
                ]);
            

            } catch (\Throwable $e) {
                $this->logger?->error('Echec de l\'envoi du mail d\'escalade.',[
                    'request_id' => $request->getId(),
                    'to' => $emailAddress,
                    'status' => $request->getStatus(),
                    'error' => $e->getMessage(),
                ]);
            }
        }
    }

    private function sendEmail(string $to, AccessRequest $request, string $comment, bool $isAction): void
    {
        $subjectTag = $isAction ? 'ACTION REQUISE' : 'INFO';
        $template = $isAction ? 'emails/notification_action.html.twig' : 'emails/notification_info.html.twig';

        $email = (new TemplatedEmail())
            ->from($this->resolveMailerFrom())
            ->to($to)
            ->subject($this->buildSubject($subjectTag, $request))
            ->htmlTemplate($template)
            ->context([
                'request' => $request,
                'status_label' => $this->getLabel((string) $request->getStatus()),
                'last_comment' => $comment,
            ]);

        $this->mailer->send($email);
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

    private function logInfo(string $to, AccessRequest $request, bool $isAction): void
    {
        $this->logger?->info('Notification workflow envoyée.', [
            'request_id' => $request->getId(),
            'to' => $to,
            'type' => $isAction ? 'ACTION' : 'INFO',
            'status' => $request->getStatus(),
            'from' => $this->resolveMailerFrom(),
        ]);
    }

    private function logError(string $message, AccessRequest $request, \Throwable $e, string $to, bool $isAction): void
    {
        $this->logger?->error($message, [
            'request_id' => $request->getId(),
            'to' => $to,
            'is_action' => $isAction,
            'status' => $request->getStatus(),
            'error' => $e->getMessage(),
        ]);
    }

    private function getLabel(string $status): string
    {
        return self::LABELS[$status] ?? $status;
    }

    private function buildSubject(string $prefix, AccessRequest $request): string
    {
        return sprintf(
            '%s : %s (%s)',
            $prefix,
            $this->getAgentDisplayName($request),
            $this->getLabel((string) $request->getStatus())
        );
    }

    private function getAgentDisplayName(AccessRequest $request): string
    {
        $agent = $request->getAgent();
        if ($agent === null) {
            return sprintf('Demande #%d', $request->getId());
        }

        $firstname = trim((string) $agent->getFirstname());
        $lastname = trim((string) $agent->getLastname());
        $fullName = trim($firstname . ' ' . mb_strtoupper($lastname));

        return $fullName !== '' ? $fullName : sprintf('Demande #%d', $request->getId());
    }
}