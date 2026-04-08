<?php

namespace App\Entity;

use App\Repository\ServiceRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: ServiceRepository::class)]
class Service
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column]
    private ?int $id;

    #[ORM\Column(length: 100, unique: true)]
    private ?string $name;

    #[ORM\Column(length: 100)]
    private ?string $email;

    #[ORM\Column(length: 20, nullable: true)]
    private ?string $code = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'service')]
    private Collection $users;

    /**
     * @var Collection<int, Agent>
     */
    #[ORM\OneToMany(targetEntity: Agent::class, mappedBy: 'service')]
    private Collection $serviceId;

    public function __construct()
    {
        $this->users = new ArrayCollection();
        $this->serviceId = new ArrayCollection();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(?string $code): static
    {
        $this->code = $code !== '' ? $code : null;

        return $this;
    }

    /**
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setService($this);
        }

        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            // set the owning side to null (unless already changed)
            if ($user->getService() === $this) {
                $user->setService(null);
            }
        }

        return $this;
    }

    /**
     * @deprecated use getUsers()
     *
     * @return Collection<int, User>
     */
    public function getServiceId(): Collection
    {
        return $this->getUsers();
    }

    /**
     * @deprecated use addUser()
     */
    public function addServiceId(User $serviceId): static
    {
        return $this->addUser($serviceId);
    }

    /**
     * @deprecated use removeUser()
     */
    public function removeServiceId(User $serviceId): static
    {
        return $this->removeUser($serviceId);
    }
}
