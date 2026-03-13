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

    #[ORM\Column(length: 100)]
    private ?string $name;

    #[ORM\Column(length: 100)]
    private ?string $email;

    /**
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'service')]
    private Collection $service_id;

    /**
     * @var Collection<int, Agent>
     */
    #[ORM\OneToMany(targetEntity: Agent::class, mappedBy: 'service')]
    private Collection $serviceId;

    public function __construct()
    {
        $this->service_id = new ArrayCollection();
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

    /**
     * @return Collection<int, User>
     */
    public function getServiceId(): Collection
    {
        return $this->service_id;
    }

    public function addServiceId(User $serviceId): static
    {
        if (!$this->service_id->contains($serviceId)) {
            $this->service_id->add($serviceId);
            $serviceId->setService($this);
        }

        return $this;
    }

    public function removeServiceId(User $serviceId): static
    {
        if ($this->service_id->removeElement($serviceId)) {
            // set the owning side to null (unless already changed)
            if ($serviceId->getService() === $this) {
                $serviceId->setService(null);
            }
        }

        return $this;
    }
}
