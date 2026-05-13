<?php

namespace App\Form\Model;

use App\Entity\Ressource;
use App\Entity\Service;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use App\Entity\Request as AccessRequest;

// Classe de données pour la création d'une nouvelle demande d'accès
final class NewRequestData
{
    #[Assert\NotBlank(message: 'La civilité est obligatoire.')]
    #[Assert\Choice(choices: ['M.', 'Mme', 'Mlle'], message: 'La civilité est invalide.')]
    private ?string $civility = null;

    #[Assert\NotBlank(message: 'Le prénom est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le prénom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $firstname = null;

    #[Assert\NotBlank(message: 'Le nom est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'Le nom ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $lastname = null;

    #[Assert\NotBlank(message: 'L’email est obligatoire.')]
    #[Assert\Email(message: 'L’adresse email est invalide.')]
    #[Assert\Length(max: 180, maxMessage: 'L’email ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $email = null;

    #[Assert\NotNull(message: 'Le service est obligatoire.')]
    private ?Service $service = null;

    #[Assert\NotBlank(message: 'La fonction est obligatoire.')]
    #[Assert\Length(max: 100, maxMessage: 'La fonction ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $jobTitle = null;

    #[Assert\Length(max: 20, maxMessage: 'La taille du vêtement ne doit pas dépasser {{ limit }} caractères.')]
    private ?string $clothingSize = null;

    #[Assert\Length(max: 20, maxMessage: 'La taille des chaussures ne peut pas dépasser {{ limit }} caractères.')]
    private ?string $shoeSize = null;

    private ?\DateTime $arrivalDate = null;

    private ?\DateTime $departureDate = null;

    #[Assert\NotBlank(message: 'Le type de demande est obligatoire.')]
    #[Assert\Choice(
        choices: ['ouverture', 'modification', 'fermeture'],
        message: 'Le type de demande est invalide.'
    )]
    private ?string $type = 'ouverture';

    private ?AccessRequest $parentRequest = null;

    /**
     * @var array<int, Ressource>
     */
    private array $logiciels = [];

    /**
     * @var array<int, Ressource>
     */
    private array $materiels = [];

    #[Assert\NotBlank(message: 'Le commentaire est obligatoire.')]
    private ?string $commentary = null;

    #[Assert\Callback]
    public function validate(ExecutionContextInterface $context): void
    {
        // Arrivée obligatoire pour tous les types
        if (!$this->arrivalDate instanceof \DateTime) {
            $context
                ->buildViolation('La date d’arrivée est obligatoire.')
                ->atPath('arrivalDate')
                ->addViolation();
        }

        // Départ facultatif, mais si les 2 sont saisis: cohérence des dates
        if (
            $this->arrivalDate instanceof \DateTime
            && $this->departureDate instanceof \DateTime
            && $this->departureDate < $this->arrivalDate
        ) {
            $context
                ->buildViolation('La date de départ ne peut pas être antérieure à la date d’arrivée.')
                ->atPath('departureDate')
                ->addViolation();
        }

        if (in_array($this->type, ['modification', 'fermeture'], true) && !$this->parentRequest instanceof AccessRequest) {
            $context
                ->buildViolation('La demande d’origine est obligatoire pour une modification ou une fermeture.')
                ->atPath('parentRequest')
                ->addViolation();
        }
    }
    // Getters et setters pour chaque propriété
    public function getCivility(): ?string
    {
        return $this->civility;
    }

    public function setCivility(?string $civility): void
    {
        $this->civility = $civility;
    }

    public function getFirstname(): ?string
    {
        return $this->firstname;
    }

    public function setFirstname(?string $firstname): void
    {
        $this->firstname = $firstname;
    }

    public function getLastname(): ?string
    {
        return $this->lastname;
    }

    public function setLastname(?string $lastname): void
    {
        $this->lastname = $lastname;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): void
    {
        $this->email = $email;
    }

    public function getService(): ?Service
    {
        return $this->service;
    }

    public function setService(?Service $service): void
    {
        $this->service = $service;
    }

    public function getJobTitle(): ?string
    {
        return $this->jobTitle;
    }

    public function setJobTitle(?string $jobTitle): void
    {
        $this->jobTitle = $jobTitle;
    }

    public function getArrivalDate(): ?\DateTime
    {
        return $this->arrivalDate;
    }

    public function setArrivalDate(?\DateTime $arrivalDate): void
    {
        $this->arrivalDate = $arrivalDate;
    }

    public function getDepartureDate(): ?\DateTime
    {
        return $this->departureDate;
    }

    public function setDepartureDate(?\DateTime $departureDate): void
    {
        $this->departureDate = $departureDate;
    }

    public function getType(): ?string
    {
        return $this->type;
    }

    public function setType(?string $type): void
    {
        $this->type = $type;
    }

    public function getClothingSize(): ?string
    {
        return $this->clothingSize;
    }

    public function setClothingSize(?string $clothingSize): void
    {
        $this->clothingSize = $clothingSize;
    }

    public function getShoeSize(): ?string
    {
        return $this->shoeSize;
    }

    public function setShoeSize(?string $shoeSize): void
    {
        $this->shoeSize = $shoeSize;
    }

    /**
     * @return array<int, Ressource>
     */
    public function getLogiciels(): array
    {
        return $this->logiciels;
    }

    /**
     * @param array<int, Ressource> $logiciels
     */
    public function setLogiciels(array $logiciels): void
    {
        $this->logiciels = $logiciels;
    }

    /**
     * @return array<int, Ressource>
     */
    public function getMateriels(): array
    {
        return $this->materiels;
    }

    /**
     * @param array<int, Ressource> $materiels
     */
    public function setMateriels(array $materiels): void
    {
        $this->materiels = $materiels;
    }

    public function getCommentary(): ?string
    {
        return $this->commentary;
    }

    public function setCommentary(?string $commentary): void
    {
        $this->commentary = $commentary;
    }

    public function getParentRequest(): ?AccessRequest
    {
        return $this->parentRequest;
    }

    public function setParentRequest(?AccessRequest $parentRequest): void
    {
        $this->parentRequest = $parentRequest;
    }
}
