<?php

namespace App\Entity;

use App\Repository\RessourceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RessourceRepository::class)]
class Ressource
{
    public const ASSIGNMENT_ATTRIBUE = 'attribue';
    public const ASSIGNMENT_NON_ATTRIBUE = 'non_attribue';

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

    #[ORM\Column(length: 30)]
    private string $assignmentStatus = self::ASSIGNMENT_NON_ATTRIBUE;

    /**
     * @var Collection<int, Request>
     */
    #[ORM\ManyToMany(targetEntity: Request::class, mappedBy: 'ressources')]
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

    public function getAssignmentStatus(): string
    {
        return $this->assignmentStatus;
    }

    public function setAssignmentStatus(string $assignmentStatus): static
    {
        $this->assignmentStatus = $assignmentStatus;

        return $this;
    }

    public static function getAssignmentStatusLabels(): array
    {
        return [
            self::ASSIGNMENT_ATTRIBUE => 'Attribué',
            self::ASSIGNMENT_NON_ATTRIBUE => 'Non attribué',
        ];
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
            $ressourceRequest->addRessource($this);
        }

        return $this;
    }

    public function removeRessourceRequest(Request $ressourceRequest): static
    {
        if ($this->ressource_request->removeElement($ressourceRequest)) {
            $ressourceRequest->removeRessource($this);
        }

        return $this;
    }
}
