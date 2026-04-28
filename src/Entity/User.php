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
#[UniqueEntity(fields: ['email'], message: 'Cet email est déjà utilisé.')]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 100)]
    private ?string $firstname = null;

    #[ORM\Column(length: 100)]
    private ?string $lastname = null;

    #[ORM\Column(length: 150, unique: true)]
    private ?string $email;

    #[ORM\Column(length: 255)]
    private ?string $password;

    #[ORM\Column]
    private ?bool $isActive;

    #[ORM\Column]
    private bool $mustChangePassword = false;

    #[ORM\ManyToOne(inversedBy: 'users')]
    private ?Role $role = null;

    #[ORM\ManyToOne(inversedBy: 'users')]
    private ?Service $service = null;

    /**
     * @var Collection<int, Request>
     */
    #[ORM\OneToMany(targetEntity: Request::class, mappedBy: 'author')]
    private Collection $requests;

    /**
     * @var Collection<int, WorkflowHistory>
     */
    #[ORM\OneToMany(targetEntity: WorkflowHistory::class, mappedBy: 'user')]
    private Collection $workflowHistories;

    public function __construct()
    {
        $this->requests = new ArrayCollection();
        $this->workflowHistories = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        $fullName = trim(sprintf('%s %s', $this->firstname ?? '', $this->lastname ?? ''));
        if ($fullName !== '') {
            return $fullName;
        }

        return $this->email ?? 'Utilisateur';
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
    public function getRequests(): Collection
    {
        return $this->requests;
    }

    public function addRequest(Request $request): static
    {
        if (!$this->requests->contains($request)) {
            $this->requests->add($request);
            $request->setAuthor($this);
        }

        return $this;
    }

    public function removeRequest(Request $request): static
    {
        if ($this->requests->removeElement($request)) {
            // set the owning side to null (unless already changed)
            if ($request->getAuthor() === $this) {
                $request->setAuthor(null);
            }
        }

        return $this;
    }

    /**
     * @return Collection<int, WorkflowHistory>
     */
    public function getWorkflowHistories(): Collection
    {
        return $this->workflowHistories;
    }

    public function addWorkflowHistory(WorkflowHistory $workflowHistory): static
    {
        if (!$this->workflowHistories->contains($workflowHistory)) {
            $this->workflowHistories->add($workflowHistory);
            $workflowHistory->setUser($this);
        }

        return $this;
    }

    public function removeWorkflowHistory(WorkflowHistory $workflowHistory): static
    {
        if ($this->workflowHistories->removeElement($workflowHistory)) {
            // set the owning side to null (unless already changed)
            if ($workflowHistory->getUser() === $this) {
                $workflowHistory->setUser(null);
            }
        }

        return $this;
    }

    /**
     * @deprecated use getRequests()
     *
     * @return Collection<int, Request>
     */
    public function getAuthorId(): Collection
    {
        return $this->getRequests();
    }

    /**
     * @deprecated use addRequest()
     */
    public function addAuthorId(Request $authorId): static
    {
        return $this->addRequest($authorId);
    }

    /**
     * @deprecated use removeRequest()
     */
    public function removeAuthorId(Request $authorId): static
    {
        return $this->removeRequest($authorId);
    }

    /**
     * @deprecated use getWorkflowHistories()
     *
     * @return Collection<int, WorkflowHistory>
     */
    public function getUserId(): Collection
    {
        return $this->getWorkflowHistories();
    }

    /**
     * @deprecated use addWorkflowHistory()
     */
    public function addUserId(WorkflowHistory $userId): static
    {
        return $this->addWorkflowHistory($userId);
    }

    /**
     * @deprecated use removeWorkflowHistory()
     */
    public function removeUserId(WorkflowHistory $userId): static
    {
        return $this->removeWorkflowHistory($userId);
    }
}
