<?php

namespace App\Entity;

use App\Repository\LoginAuditDailyStatRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: LoginAuditDailyStatRepository::class)]
#[ORM\Table(name: 'login_audit_daily_stat')]
#[ORM\UniqueConstraint(name: 'uniq_login_audit_daily_stat_date', columns: ['stat_date'])]
class LoginAuditDailyStat
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(type: Types::DATE_IMMUTABLE, name: 'stat_date')]
    private \DateTimeImmutable $statDate;

    #[ORM\Column(options: ['default' => 0])]
    private int $successCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $failureCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $logoutCount = 0;

    #[ORM\Column(options: ['default' => 0])]
    private int $purgedCount = 0;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $updatedAt;

    public function __construct()
    {
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function getId(): ?int { return $this->id; }

    public function getStatDate(): \DateTimeImmutable { return $this->statDate; }
    public function setStatDate(\DateTimeImmutable $statDate): static { $this->statDate = $statDate; return $this; }

    public function getSuccessCount(): int { return $this->successCount; }
    public function setSuccessCount(int $successCount): static { $this->successCount = $successCount; return $this; }

    public function getFailureCount(): int { return $this->failureCount; }
    public function setFailureCount(int $failureCount): static { $this->failureCount = $failureCount; return $this; }

    public function getLogoutCount(): int { return $this->logoutCount; }
    public function setLogoutCount(int $logoutCount): static { $this->logoutCount = $logoutCount; return $this; }

    public function getPurgedCount(): int { return $this->purgedCount; }
    public function setPurgedCount(int $purgedCount): static { $this->purgedCount = $purgedCount; return $this; }

    public function getUpdatedAt(): \DateTimeImmutable { return $this->updatedAt; }
    public function setUpdatedAt(\DateTimeImmutable $updatedAt): static { $this->updatedAt = $updatedAt; return $this; }
}