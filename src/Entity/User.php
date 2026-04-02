<?php

namespace App\Entity;

use App\Repository\UserRepository;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;

#[ORM\Entity(repositoryClass: UserRepository::class)]
#[UniqueEntity(fields: ['email'], message: 'Cet email est deja utilise.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $firstname = null;

    #[ORM\Column(length: 100, nullable: true)]
    private ?string $lastname = null;

    #[ORM\Column(length: 150, unique: true)]
    private ?string $email;

    #[ORM\Column(length: 255)]
    private ?string $password;

    #[ORM\Column]
    private ?bool $isActive;

    #[ORM\Column]
    private bool $mustChangePassword = false;

    #[ORM\ManyToOne(inversedBy: 'role_id')]
    private ?Role $role = null;

    #[ORM\ManyToOne(inversedBy: 'service_id')]
    private ?Service $service = null;

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

    public function setFirstname(?string $firstname): static
    {
        $value = $firstname !== null ? trim($firstname): null;
        $this->firstname = $value !== '' ? $value : null;

        return $this;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function getDisplayName(): string
    {
        $full = trim(($this->firstname ?? '') . ' ' . ($this->lastname ?? ''));
        if ($full !== '') {
            return $full;
        }

        if ($this->service !== null && $this->service->getName() !== null) {
            return 'Compte ' . $this->service->getName();
        }

        return $this->email ?? 'Compte utilisateur';
    }

    public function setLastname(?string $lastname): static
    {
        $value = $lastname !== null ? trim($lastname): null;
        $this->lastname = $value !== '' ? $value : null;

        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = strtolower(trim($email));

        return $this;
    }

    public function getUserIdentifier(): string
    {
        return $this->email ?? '';
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

    /**
     * @return list<string>
     */
    public function getRoles(): array
    {
        $roles = ['ROLE_USER'];

        // Rôle d'accès (ADMIN, USER…)
        if ($this->role instanceof Role && $this->role->getLabel() !== null) {
            $roleFromDb = strtoupper(trim($this->role->getLabel()));
            if ($roleFromDb !== '') {
                $roles[] = str_starts_with($roleFromDb, 'ROLE_') ? $roleFromDb : sprintf('ROLE_%s', $roleFromDb);
            }
        }

        // Rôle workflow dérivé du code du service (RH, ST, DSI…)
        if ($this->service instanceof Service && $this->service->getCode() !== null) {
            $serviceCode = strtoupper(trim($this->service->getCode()));
            if ($serviceCode !== '') {
                $roles[] = sprintf('ROLE_%s', $serviceCode);
            }
        }

        return array_values(array_unique($roles));
    }

    public function eraseCredentials(): void
    {
        // Nothing to clear for now.
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

    public function isMustChangePassword(): bool
    {
        return $this->mustChangePassword;
    }

    public function setMustChangePassword(bool $mustChangePassword): static
    {
        $this->mustChangePassword = $mustChangePassword;

        return $this;
    }

    public function getRole(): ?Role
    {
        return $this->role;
    }

    public function setRole(?Role $role): static
    {
        $this->role = $role;

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
