<?php

namespace App\Entity;

use App\Repository\RequestRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RequestRepository::class)]
class Request
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 150)]
    private ?string $type;

    #[ORM\Column(length: 150)]
    private ?string $status;

    #[ORM\Column(type: Types::DATE_MUTABLE)]
    private ?\DateTime $arrivalDate;

    #[ORM\Column(type: Types::DATE_MUTABLE, nullable: true)]
    private ?\DateTime $departureDate = null;

    #[ORM\Column(type: Types::TEXT, nullable: true)]
    private ?string $commentary = null;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $creationDate;

    #[ORM\Column(type: Types::DATETIME_IMMUTABLE)]
    private ?\DateTimeImmutable $updateDate = null;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $workflowSnapshot = null;

    #[ORM\ManyToOne(inversedBy: 'agent_id')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Agent $agent = null;

    #[ORM\ManyToOne(inversedBy: 'author_id')]
    #[ORM\JoinColumn(nullable: false)]
    private ?User $author = null;

    /**
     * @var Collection<int, Ressource>
     */
    #[ORM\ManyToMany(targetEntity: Ressource::class, inversedBy: 'ressource_request')]
    private Collection $ressources;

    /**
     * @var Collection<int, WorkflowHistory>
     */
    #[ORM\OneToMany(targetEntity: WorkflowHistory::class, mappedBy: 'request')]
    private Collection $requestId;

    /**
     * Demande d'origine (ex: une ouverture traitée)
     */
    #[ORM\ManyToOne(targetEntity: self::class, inversedBy: 'childRequests')]
    #[ORM\JoinColumn(onDelete: 'SET NULL', nullable: true)]
    private ?self $parentRequest = null;

    /**
     * Demandes filles (modification/fermeture liées à une demande d'origine)
     *
     * @var Collection<int, self>
     */
    #[ORM\OneToMany(targetEntity: self::class, mappedBy: 'parentRequest')]
    private Collection $childRequests;

    public function __construct()
    {
        $this->ressources = new ArrayCollection();
        $this->requestId = new ArrayCollection();
        $this->childRequests = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getReference(): string
    {
        $prefix = match ($this->type) {
            'ouverture' => 'OUV',
            'modification' => 'MOD',
            'fermeture' => 'FER',
            default => 'REQ',
        };

        return sprintf('%s-%03d', $prefix, $this->id ?? 0);
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(string $type): static
    {
        $this->type = $type;

        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;

        return $this;
    }

    public function getArrivalDate(): ?\DateTime
    {
        return $this->arrivalDate;
    }

    public function setArrivalDate(\DateTime $arrivalDate): static
    {
        $this->arrivalDate = $arrivalDate;

        return $this;
    }

    public function getDepartureDate(): ?\DateTime
    {
        return $this->departureDate;
    }

    public function setDepartureDate(?\DateTime $departureDate): static
    {
        $this->departureDate = $departureDate;

        return $this;
    }

    public function getCommentary(): ?string
    {
        return $this->commentary;
    }

    public function setCommentary(?string $commentary): static
    {
        $this->commentary = $commentary;

        return $this;
    }

    public function getCreationDate(): ?\DateTimeImmutable
    {
        return $this->creationDate;
    }

    public function setCreationDate(\DateTimeImmutable $creationDate): static
    {
        $this->creationDate = $creationDate;

        return $this;
    }

    public function getUpdateDate(): ?\DateTimeImmutable
    {
        return $this->updateDate;
    }

    public function setUpdateDate(\DateTimeImmutable $updateDate): static
    {
        $this->updateDate = $updateDate;

        return $this;
    }

    public function getWorkflowSnapshot(): ?array
    {
        return $this->workflowSnapshot;
    }

    public function setWorkflowSnapshot(?array $workflowSnapshot): static
    {
        $this->workflowSnapshot = $workflowSnapshot;

        return $this;
    }

    public function getAgent(): ?Agent
    {
        return $this->agent;
    }

    public function setAgent(?Agent $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getAuthor(): ?User
    {
        return $this->author;
    }

    public function setAuthor(?User $author): static
    {
        $this->author = $author;

        return $this;
    }

    /**
     * @return Collection<int, Ressource>
     */
    public function getRessources(): Collection
    {
        return $this->ressources;
    }

    public function addRessource(Ressource $ressource): static
    {
        if (!$this->ressources->contains($ressource)) {
            $this->ressources->add($ressource);
        }

        return $this;
    }

    public function removeRessource(Ressource $ressource): static
    {
        $this->ressources->removeElement($ressource);

        return $this;
    }

    /**
     * @return Collection<int, WorkflowHistory>
     */
    public function getRequestId(): Collection
    {
        return $this->requestId;
    }

    public function addRequestId(WorkflowHistory $requestId): static
    {
        if (!$this->requestId->contains($requestId)) {
            $this->requestId->add($requestId);
            $requestId->setRequest($this);
        }

        return $this;
    }

    public function removeRequestId(WorkflowHistory $requestId): static
    {
        if ($this->requestId->removeElement($requestId)) {
            // set the owning side to null (unless already changed)
            if ($requestId->getRequest() === $this) {
                $requestId->setRequest(null);
            }
        }

        return $this;
    }

    public function getParentRequest(): ?self
    {
        return $this->parentRequest;
    }

    public function setParentRequest(?self $parentRequest): static
    {
        $this->parentRequest = $parentRequest;

        return $this;
    }

    /**
     * @return Collection<int, self>
     */
    public function getChildRequests(): Collection
    {
        return $this->childRequests;
    }

    public function addChildRequest(self $childRequest): static
    {
        if (!$this->childRequests->contains($childRequest)) {
            $this->childRequests->add($childRequest);
            $childRequest->setParentRequest($this);
        }

        return $this;
    }

    public function removeChildRequest(self $childRequest): static
    {
        if ($this->childRequests->removeElement($childRequest)) {
            if ($childRequest->getParentRequest() === $this) {
                $childRequest->setParentRequest(null);
            }
        }

        return $this;
    }

    public function hasProcessedReplacementChild(): bool
    {
        foreach ($this->getChildRequests() as $child) {
            $isReplacementType = in_array($child->getType(), ['modification', 'fermeture'], true);
            $isProcessed = $child->getStatus() === 'traitee';

            if ($isReplacementType && $isProcessed) {
                return true;
            }
        }

        return false;
    }

    public function isCurrentState(): bool
    {
        return !$this->hasProcessedReplacementChild();
    }


    public function getCurrentStateLabel(): string
    {
        $typeLabel = match ($this->getType()) {
            'ouverture' => 'Ouverture',
            'modification' => 'Modification',
            'fermeture' => 'Fermeture',
            default => 'Demande',
        };

        if (!$this->isCurrentState()) {
            return 'Remplacée - ' . $typeLabel;
        }

        if ($this->getType() === 'fermeture' && $this->getStatus() === 'traitee') {
            return 'Clôturée - Fermeture';
        }

        return 'Active - ' . $typeLabel;
    }

    public function getCurrentStateBadgeClass(): string
    {
        return match (true) {
            str_starts_with($this->getCurrentStateLabel(), 'Active - Ouverture') => 'success',
            str_starts_with($this->getCurrentStateLabel(), 'Active - Modification') => 'primary',
            str_starts_with($this->getCurrentStateLabel(), 'Active - Fermeture') => 'dark',
            str_starts_with($this->getCurrentStateLabel(), 'Clôturée - Fermeture') => 'dark',
            str_starts_with($this->getCurrentStateLabel(), 'Remplacée') => 'secondary',
            default => 'secondary',
        };
    }
}
