<?php

namespace App\Entity;

use App\Repository\AgentRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: AgentRepository::class)]
class Agent
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 100)]
    private ?string $civility;

    #[ORM\Column(length: 100)]
    private ?string $firstname;

    #[ORM\Column(length: 100)]
    private ?string $lastname;

    #[ORM\Column(length: 100)]
    private ?string $jobTitle;

    #[ORM\Column(length: 180, nullable: true)]
    private ?string $email = null;

    #[ORM\ManyToOne(inversedBy: 'serviceId')]
    private ?Service $service;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $clothingSize = null;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $shoeSize = null;

    /**
     * @var Collection<int, Request>
     */
    #[ORM\OneToMany(targetEntity: Request::class, mappedBy: 'agent')]
    private Collection $agent_id;

    public function __construct()
    {
        $this->agent_id = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getCivility(): ?string
    {
        return $this->civility;
    }

    public function setCivility(string $civility): static
    {
        $this->civility = $civility;

        return $this;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(string $firstname): static
    {
        $this->firstname = $firstname;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(string $lastname): static
    {
        $this->lastname = $lastname;

        return $this;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(string $jobTitle): static
    {
        $this->jobTitle = $jobTitle;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(?Service $service): static
    {
        $this->service = $service;

        return $this;
    }

    public function getClothingSize(): ?string
    {
        return $this->clothingSize;
    }

    public function setClothingSize(?string $clothingSize): static
    {
        $this->clothingSize = $clothingSize;

        return $this;
    }

    public function getShoeSize(): ?string
    {
        return $this->shoeSize;
    }

    public function setShoeSize(?string $shoeSize): static
    {
        $this->shoeSize = $shoeSize;

        return $this;
    }



    /**
     * @return Collection<int, Request>
     */
    public function getAgentId(): Collection
    {
        return $this->agent_id;
    }

    public function addAgentId(Request $agentId): static
    {
        if (!$this->agent_id->contains($agentId)) {
            $this->agent_id->add($agentId);
            $agentId->setAgent($this);
        }

        return $this;
    }

    public function removeAgentId(Request $agentId): static
    {
        if ($this->agent_id->removeElement($agentId)) {
            // set the owning side to null (unless already changed)
            if ($agentId->getAgent() === $this) {
                $agentId->setAgent(null);
            }
        }

        return $this;
    }
}
