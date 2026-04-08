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
    public const TYPE_OUVERTURE = 'ouverture';
    public const TYPE_MODIFICATION = 'modification';
    public const TYPE_FERMETURE = 'fermeture';

    public const STATUS_EN_ATTENTE_RH = 'en_attente_rh';
    public const STATUS_EN_ATTENTE_ST = 'en_attente_st';
    public const STATUS_EN_ATTENTE_DSI = 'en_attente_dsi';
    public const STATUS_TRAITEE = 'traitee';
    public const STATUS_REFUSEE_RH = 'refusee_rh';
    public const STATUS_REFUSEE_ST = 'refusee_st';
    public const STATUS_REFUSEE_DSI = 'refusee_dsi';

    public const TYPES = [
        self::TYPE_OUVERTURE,
        self::TYPE_MODIFICATION,
        self::TYPE_FERMETURE,
    ];

    public const WORKFLOW_STATUSES = [
        self::STATUS_EN_ATTENTE_RH,
        self::STATUS_EN_ATTENTE_ST,
        self::STATUS_EN_ATTENTE_DSI,
        self::STATUS_TRAITEE,
        self::STATUS_REFUSEE_RH,
        self::STATUS_REFUSEE_ST,
        self::STATUS_REFUSEE_DSI,
    ];

    public const TYPE_LABELS = [
        self::TYPE_OUVERTURE => 'Ouverture',
        self::TYPE_MODIFICATION => 'Modification',
        self::TYPE_FERMETURE => 'Fermeture',
    ];

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

    #[ORM\Version]
    #[ORM\Column(type:'integer')]
    private int $version = 1;

    #[ORM\Column(type: Types::JSON, nullable: true)]
    private ?array $workflowSnapshot = null;

    #[ORM\ManyToOne(inversedBy: 'agent_id')]
    #[ORM\JoinColumn(nullable: false)]
    private ?Agent $agent = null;

    #[ORM\ManyToOne(inversedBy: 'requests')]
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
            self::TYPE_OUVERTURE => 'OUV',
            self::TYPE_MODIFICATION => 'MOD',
            self::TYPE_FERMETURE => 'FER',
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

    public function getVersion(): int
    {
        return $this->version;
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
            $isReplacementType = in_array($child->getType(), [self::TYPE_MODIFICATION, self::TYPE_FERMETURE], true);
            $isProcessed = $child->getStatus() === self::STATUS_TRAITEE;

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
        $typeLabel = self::TYPE_LABELS[$this->getType() ?? ''] ?? 'Demande';

        if (!$this->isCurrentState()) {
            return 'Remplacée - ' . $typeLabel;
        }

        if ($this->getType() === self::TYPE_FERMETURE && $this->getStatus() === self::STATUS_TRAITEE) {
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
