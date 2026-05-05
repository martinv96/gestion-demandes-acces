<?php

namespace App\Entity;

use App\Repository\LoginAuditRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoginAuditRepository::class)]
#[ORM\Table(name: 'login_audit')]
#[ORM\Index(columns: ['occurred_at'], name: 'idx_login_audit_occurred_at')]
#[ORM\Index(columns: ['event_type'], name: 'idx_login_audit_event_type')]
class LoginAudit
{
    public const EVENT_SUCCESS = 'success';
    public const EVENT_FAILURE = 'failure';
    public const EVENT_LOGOUT = 'logout';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 20)]
    private string $eventType;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $user = null;

    #[ORM\Column(length: 45, nullable: true)]
    private ?string $ipAddress = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $userAgent = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firewall = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $occurredAt;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $details = null;

    public function __construct()
    {
        $this->occurredAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getEventType(): string { return $this->eventType; }
    public function setEventType(string $eventType): static { $this->eventType = $eventType; return $this; }

    public function getEmail(): ?string { return $this->email; }
    public function setEmail(?string $email): static { $this->email = $email; return $this; }

    public function getUser(): ?User { return $this->user; }
    public function setUser(?User $user): static { $this->user = $user; return $this; }

    public function getIpAddress(): ?string { return $this->ipAddress; }
    public function setIpAddress(?string $ipAddress): static { $this->ipAddress = $ipAddress; return $this; }

    public function getUserAgent(): ?string { return $this->userAgent; }
    public function setUserAgent(?string $userAgent): static { $this->userAgent = $userAgent; return $this; }

    public function getFirewall(): ?string { return $this->firewall; }
    public function setFirewall(?string $firewall): static { $this->firewall = $firewall; return $this; }

    public function getOccurredAt(): \DateTimeImmutable { return $this->occurredAt; }
    public function setOccurredAt(\DateTimeImmutable $occurredAt): static { $this->occurredAt = $occurredAt; return $this; }

    public function getDetails(): ?string { return $this->details; }
    public function setDetails(?string $details): static { $this->details = $details; return $this; }
}