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

    #[ORM\Column(length: 255)]
    private ?string $commentary;

    #[ORM\Column]
    private ?\DateTime $creationDate;

    #[ORM\Column]
    private ?\DateTime $updateDate = null;

    #[ORM\ManyToOne(inversedBy: 'agent_id')]
    private ?agent $agent = null;

    #[ORM\ManyToOne(inversedBy: 'author_id')]
    private ?user $author = null;

    #[ORM\ManyToOne(inversedBy: 'ressource_request')]
    private ?Ressource $ressources = null;

    /**
     * @var Collection<int, WorkflowHistory>
     */
    #[ORM\OneToMany(targetEntity: WorkflowHistory::class, mappedBy: 'request')]
    private Collection $requestId;

    public function __construct()
    {
        $this->requestId = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getCreationDate(): ?\DateTime
    {
        return $this->creationDate;
    }

    public function setCreationDate(\DateTime $creationDate): static
    {
        $this->creationDate = $creationDate;

        return $this;
    }

    public function getUpdateDate(): ?\DateTime
    {
        return $this->updateDate;
    }

    public function setUpdateDate(\DateTime $updateDate): static
    {
        $this->updateDate = $updateDate;

        return $this;
    }

    public function getAgent(): ?agent
    {
        return $this->agent;
    }

    public function setAgent(?agent $agent): static
    {
        $this->agent = $agent;

        return $this;
    }

    public function getAuthor(): ?user
    {
        return $this->author;
    }

    public function setAuthor(?user $author): static
    {
        $this->author = $author;

        return $this;
    }

    public function getRessources(): ?Ressource
    {
        return $this->ressources;
    }

    public function setRessources(?Ressource $ressources): static
    {
        $this->ressources = $ressources;

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
}
