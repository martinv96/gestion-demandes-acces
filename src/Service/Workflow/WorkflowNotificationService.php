<?php

namespace App\Service\Workflow;

use App\Entity\Request as AccessRequest;
use App\Repository\ServiceRepository;
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
        private ServiceRepository $serviceRepository,
        private WorkflowStateResolver $stateResolver,
        private string $mailerFrom = 'no-reply@localhost',
        private ?LoggerInterface $logger = null
    ) {}

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

    public function sendReminder(AccessRequest $request, string $message = 'Ceci est un rappel automatique.'): void
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
                            'status_label' => $this->getLabel((string) $request->getStatus()),
                            'reminder_message' => $message,
                        ]);
                    $this->mailer->send($email);
                    $this->logInfo($emailAddress, $request, true);
                } catch (\Throwable $e) {
                    $this->logError("Echec de l'envoi du mail de rappel", $request, $e, $emailAddress, true);
                }
            }
        }
    }

    public function sendEscalation(AccessRequest $request, string $message = 'La demande est toujours en attente et nécessite une intervention.'): void
    {
        try {
            $activeUsers = $this->userRepository->findBy(['isActive' => true]);
        } catch (\Throwable) {
            return;
        }

        foreach ($activeUsers as $user) {
            if (!is_object($user) || !method_exists($user, 'getEmail') || !method_exists($user, 'getRoles')) {
                continue;
            }

            $roles = (array) $user->getRoles();
            $mustReceiveEscalation = in_array('ROLE_ADMIN', $roles, true) || in_array('ROLE_RH', $roles, true);

            if (!$mustReceiveEscalation) {
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
                        'status_label' => $this->getLabel((string) $request->getStatus()),
                        'last_comment' => $message,
                    ]);

                $this->mailer->send($email);

                $this->logger?->info('Escalade workflow envoyée.', [
                    'request_id' => $request->getId(),
                    'to' => $emailAddress,
                    'status' => $request->getStatus(),
                ]);
            } catch (\Throwable $e) {
                $this->logger?->error('Echec de l\'envoi du mail d\'escalade.', [
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
            '%s : %s - %s (%s)',
            $prefix,
            $this->getRequestTypeLabel($request),
            $this->getAgentDisplayName($request),
            $this->getLabel((string) $request->getStatus())
        );
    }

    private function getRequestTypeLabel(AccessRequest $request): string
    {
        return match ((string) $request->getType()) {
            AccessRequest::TYPE_FERMETURE => 'Départ',
            AccessRequest::TYPE_OUVERTURE => 'Arrivée',
            default => 'Demande',
        };
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

    public function sendNoteAccompagnement(AccessRequest $request): void
    {
        //on commence par vérifier par sécurité que la demande est bien traitée
        if ((string) $request->getStatus() !== AccessRequest::STATUS_TRAITEE) {
            return;
        }

        // on récupère l'agen lié à la demande
        $agent = $request->getAgent();

        if ($agent === null) {
            $this->logger?->warning('Impossible d\'envoyer la note d\'acompagnement : aucun agent rattaché à la demande.', [
                'request_id' => $request->getId()
            ]);
            return;
        }

        $emailAddress = trim((string) $agent->getEmail());

        // on récupère l'adresse mail saisie sur la demande
        if ($emailAddress === '') {
            $this->logger?->warning('Impossible d\'envoyer la note d\'accompagnement : l\'agent n\'a pas d\'adresse email renseignée.', [
                'request_id' => $request->getId(),
                'agent_id' => $agent->getId()
            ]);
            return;
        }

        try {
            $email = (new TemplatedEmail())
                ->from($this->resolveMailerFrom())
                ->to($emailAddress)
                ->subject('GDAP : Vos accès et matériels sont prêts - ' . $agent->getFirstname() . ' ' . $agent->getLastname())
                ->htmlTemplate('emails/note_accompagnement.html.twig')
                ->context([
                    'request' => $request,
                ]);

            $this->mailer->send($email);

            $this->logger?->info('Note d\'accompagnement envoyée avec succès à l\'agent.', [
                'request_id' => $request->getId(),
                'to' => $emailAddress
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('Echec de l\'envoi de la note d\'accompagnement.', [
                'request_id' => $request->getId(),
                'error' => $e->getMessage()
            ]);

            throw new \RuntimeException(
                sprintf('Envoi de la note d\'accompagnement impossible pour la demande #%d : %s', (int) $request->getId(), $e->getMessage()),
                0,
                $e
            );
        }
    }

    /**
     * @return array{recipients:int,sent:int,failed:int}
     */
    public function sendManualServiceReminder(AccessRequest $request, string $serviceTarget, string $customMessage = ''): array
    {
        // 1. Mapping du service cible vers les codes services et rôles workflow associés.
        $normalizedTarget = mb_strtoupper(trim($serviceTarget));
        $targetConfig = match ($normalizedTarget) {
            'RH' => ['codes' => ['RH'], 'roles' => ['ROLE_RH']],
            'ST' => ['codes' => ['ST'], 'roles' => ['ROLE_ST']],
            'DSI' => ['codes' => ['DSI'], 'roles' => ['ROLE_DSI']],
            'FIN', 'FINANCES' => ['codes' => ['FIN', 'FINANCES'], 'roles' => ['ROLE_FIN', 'ROLE_FINANCES']],
            default => null,
        };

        if ($targetConfig === null) {
            $this->logger?->warning('Tentative de relance manuelle sur un service inconnu.', [
                'request_id' => $request->getId(),
                'target' => $serviceTarget
            ]);
            return ['recipients' => 0, 'sent' => 0, 'failed' => 0];
        }

        $targetCodes = $targetConfig['codes'];
        $targetRoles = $targetConfig['roles'];

        /** @var array<string, string> $recipients */
        $recipients = [];

        // 2. Priorité à l'adresse mail du service configuré (table Service).
        try {
            $services = $this->serviceRepository->findAll();
            foreach ($services as $service) {
                $serviceCode = mb_strtoupper(trim((string) $service->getCode()));
                if ($serviceCode !== '' && in_array($serviceCode, $targetCodes, true)) {
                    $serviceEmail = trim((string) $service->getEmail());
                    if ($serviceEmail !== '') {
                        $recipients[$serviceEmail] = 'service';
                    }
                }
            }

            // Fallback: certains services n'ont pas de code workflow, on tente un rapprochement par nom.
            if ($recipients === []) {
                $nameKeywords = match ($normalizedTarget) {
                    'RH' => ['RESSOURCES HUMAINES', 'RH'],
                    'ST' => ['SERVICE TECHNIQUE', 'TECHNIQUE', 'ST'],
                    'DSI' => ['DSI', 'INFORMATIQUE'],
                    'FIN', 'FINANCES' => ['FINANCES', 'FIN'],
                    default => [],
                };

                if ($nameKeywords !== []) {
                    foreach ($this->serviceRepository->findAll() as $service) {
                        $serviceName = mb_strtoupper(trim((string) $service->getName()));
                        if ($serviceName === '') {
                            continue;
                        }

                        foreach ($nameKeywords as $keyword) {
                            if (str_contains($serviceName, $keyword)) {
                                $serviceEmail = trim((string) $service->getEmail());
                                if ($serviceEmail !== '') {
                                    $recipients[$serviceEmail] = 'service_name_fallback';
                                }
                                break;
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            $this->logger?->error('Impossible de récupérer les services pour la relance manuelle.', [
                'request_id' => $request->getId(),
                'service' => $serviceTarget,
                'target_codes' => $targetCodes,
                'error' => $e->getMessage(),
            ]);
        }

        try {
            $activeUsers = $this->userRepository->findBy(['isActive' => true]);
        } catch (\Throwable $e) {
            $this->logger?->error('Impossible de récuréper les utilisateurs pour la relance manuelle.', [
                'request_id' => $request->getId(),
                'error' => $e->getMessage()
            ]);
            $activeUsers = [];
        }

        // 3. Complément: utilisateurs actifs porteurs du rôle ciblé.
        foreach ($activeUsers as $user) {
            if (!is_object($user) || !method_exists($user, 'getEmail') || !method_exists($user, 'getRoles')) {
                continue;
            }

            $emailAddress = trim((string) $user->getEmail());
            if ($emailAddress === '') {
                continue;
            }

            $hasTargetRole = array_intersect($targetRoles, (array) $user->getRoles()) !== [];
            if ($hasTargetRole) {
                $recipients[$emailAddress] = 'user';
            }
        }

        if ($recipients === []) {
            $this->logger?->warning('Aucun destinataire trouvé pour la relance manuelle.', [
                'request_id' => $request->getId(),
                'service' => $serviceTarget,
                'target_codes' => $targetCodes,
                'target_roles' => $targetRoles,
            ]);

            return ['recipients' => 0, 'sent' => 0, 'failed' => 0];
        }

        // Message par défaut si l'admin n'a pas écrit de texte spécifique.
        $message = trim($customMessage) !== '' ? $customMessage : 'Un administrateur demande la relance du traitement de cette demande.';
        $sentCount = 0;
        $failedCount = 0;

        foreach ($recipients as $emailAddress => $recipientSource) {
            try {
                $email = (new TemplatedEmail())
                    ->from($this->resolveMailerFrom())
                    ->to($emailAddress)
                    ->subject($this->buildSubject('RELANCE MANUELLE - ' . mb_strtoupper($serviceTarget), $request))
                    ->htmlTemplate('emails/notification_manual_reminder.html.twig')
                    ->context([
                        'request' => $request,
                        'status_label' => $this->getLabel((string) $request->getStatus()),
                        'reminder_message' => $message,
                    ]);
                $this->mailer->send($email);
                $sentCount++;

                $this->logger?->info('Relance manuelle envoyée au service avec succès.', [
                    'request_id' => $request->getId(),
                    'service' => $serviceTarget,
                    'target_codes' => $targetCodes,
                    'target_roles' => $targetRoles,
                    'source' => $recipientSource,
                    'to' => $emailAddress,
                ]);
            } catch (\Throwable $e) {
                $failedCount++;
                $this->logError(
                    sprintf("Echec de la relance manuelle pour le service %s", $serviceTarget),
                    $request,
                    $e,
                    $emailAddress,
                    true
                );
            }
        }

        return [
            'recipients' => count($recipients),
            'sent' => $sentCount,
            'failed' => $failedCount,
        ];
    }
}
