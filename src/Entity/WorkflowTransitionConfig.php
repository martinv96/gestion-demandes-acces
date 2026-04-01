<?php

namespace App\Entity;

use App\Repository\WorkflowTransitionConfigRepository;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: WorkflowTransitionConfigRepository::class)]
class WorkflowTransitionConfig
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id = null;

    #[ORM\Column(length: 50)]
    private ?string $workflowCode = null;

    #[ORM\Column]
    private ?int $stepOrder = null;

    #[ORM\Column(length: 20)]
    private ?string $action = null;

    #[ORM\Column(length: 150)]
    private ?string $fromStatus = null;

    #[ORM\Column(length: 150)]
    private ?string $toStatus = null;

    #[ORM\Column(length: 50)]
    private ?string $requiredRole = null;

    #[ORM\Column(options: ['default' => true])]
    private ?bool $isActive = true;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getWorkflowCode(): ?string
    {
        return $this->workflowCode;
    }

    public function setWorkflowCode(string $workflowCode): static
    {
        $this->workflowCode = $workflowCode;

        return $this;
    }

    public function getStepOrder(): ?int
    {
        return $this->stepOrder;
    }

    public function setStepOrder(int $stepOrder): static
    {
        $this->stepOrder = $stepOrder;

        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(string $action): static
    {
        $this->action = $action;

        return $this;
    }

    public function getFromStatus(): ?string
    {
        return $this->fromStatus;
    }

    public function setFromStatus(string $fromStatus): static
    {
        $this->fromStatus = $fromStatus;

        return $this;
    }

    public function getToStatus(): ?string
    {
        return $this->toStatus;
    }

    public function setToStatus(string $toStatus): static
    {
        $this->toStatus = $toStatus;

        return $this;
    }

    public function getRequiredRole(): ?string
    {
        return $this->requiredRole;
    }

    public function setRequiredRole(string $requiredRole): static
    {
        $this->requiredRole = $requiredRole;

        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive ?? true;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }
}
