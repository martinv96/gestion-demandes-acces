<?php

namespace App\Entity;

use App\Repository\RessourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RessourceRepository::class)]
class Ressource
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 100)]
    private ?string $name;

    #[ORM\Column(length: 50)]
    private ?string $category;

    #[ORM\Column]
    private ?bool $isActive;

    /**
     * @var Collection<int, Request>
     */
    #[ORM\OneToMany(targetEntity: Request::class, mappedBy: 'ressources')]
    private Collection $ressource_request;

    public function __construct()
    {
        $this->ressource_request = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;

        return $this;
    }

    public function getCategory(): ?string
    {
        return $this->category;
    }

    public function setCategory(string $category): static
    {
        $this->category = $category;

        return $this;
    }

    public function isActive(): ?bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        return $this;
    }

    /**
     * @return Collection<int, Request>
     */
    public function getRessourceRequest(): Collection
    {
        return $this->ressource_request;
    }

    public function addRessourceRequest(Request $ressourceRequest): static
    {
        if (!$this->ressource_request->contains($ressourceRequest)) {
            $this->ressource_request->add($ressourceRequest);
            $ressourceRequest->setRessources($this);
        }

        return $this;
    }

    public function removeRessourceRequest(Request $ressourceRequest): static
    {
        if ($this->ressource_request->removeElement($ressourceRequest)) {
            // set the owning side to null (unless already changed)
            if ($ressourceRequest->getRessources() === $this) {
                $ressourceRequest->setRessources(null);
            }
        }

        return $this;
    }
}
