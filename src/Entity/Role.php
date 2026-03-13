<?php

namespace App\Entity;

use App\Repository\RoleRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: RoleRepository::class)]
class Role
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 100)]
    private ?string $label;

    /**
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'role')]
    private Collection $role_id;

    public function __construct()
    {
        $this->role_id = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getRoleId(): Collection
    {
        return $this->role_id;
    }

    public function addRoleId(User $roleId): static
    {
        if (!$this->role_id->contains($roleId)) {
            $this->role_id->add($roleId);
            $roleId->setRole($this);
        }

        return $this;
    }

    public function removeRoleId(User $roleId): static
    {
        if ($this->role_id->removeElement($roleId)) {
            // set the owning side to null (unless already changed)
            if ($roleId->getRole() === $this) {
                $roleId->setRole(null);
            }
        }

        return $this;
    }
}
