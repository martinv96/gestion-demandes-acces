<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: UserRepository::class)]
class User
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 100)]
    private ?string $firstname;

    #[ORM\Column(length: 100)]
    private ?string $lastname;

    #[ORM\Column(length: 150)]
    private ?string $email;

    #[ORM\Column(length: 150)]
    private ?string $password;

    #[ORM\Column]
    private ?bool $isActive;

    #[ORM\ManyToOne(inversedBy: 'role_id')]
    private ?role $role = null;

    #[ORM\ManyToOne(inversedBy: 'service_id')]
    private ?service $service = null;

    /**
     * @var Collection<int, Request>
     */
    #[ORM\OneToMany(targetEntity: Request::class, mappedBy: 'author')]
    private Collection $author_id;

    /**
     * @var Collection<int, WorkflowHistory>
     */
    #[ORM\OneToMany(targetEntity: WorkflowHistory::class, mappedBy: 'user')]
    private Collection $userId;

    public function __construct()
    {
        $this->author_id = new ArrayCollection();
        $this->userId = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getPassword(): ?string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

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

    public function getRole(): ?role
    {
        return $this->role;
    }

    public function setRole(?role $role): static
    {
        $this->role = $role;

        return $this;
    }

    public function getService(): ?service
    {
        return $this->service;
    }

    public function setService(?service $service): static
    {
        $this->service = $service;

        return $this;
    }

    /**
     * @return Collection<int, Request>
     */
    public function getAuthorId(): Collection
    {
        return $this->author_id;
    }

    public function addAuthorId(Request $authorId): static
    {
        if (!$this->author_id->contains($authorId)) {
            $this->author_id->add($authorId);
            $authorId->setAuthor($this);
        }

        return $this;
    }

    public function removeAuthorId(Request $authorId): static
    {
        if ($this->author_id->removeElement($authorId)) {
            // set the owning side to null (unless already changed)
            if ($authorId->getAuthor() === $this) {
                $authorId->setAuthor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, WorkflowHistory>
     */
    public function getUserId(): Collection
    {
        return $this->userId;
    }

    public function addUserId(WorkflowHistory $userId): static
    {
        if (!$this->userId->contains($userId)) {
            $this->userId->add($userId);
            $userId->setUser($this);
        }

        return $this;
    }

    public function removeUserId(WorkflowHistory $userId): static
    {
        if ($this->userId->removeElement($userId)) {
            // set the owning side to null (unless already changed)
            if ($userId->getUser() === $this) {
                $userId->setUser(null);
            }
        }

        return $this;
    }
}
