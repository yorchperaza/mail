<?php

declare(strict_types=1);

namespace App\Entity;

use MonkeysLegion\Entity\Attributes\Entity;
use MonkeysLegion\Entity\Attributes\Field;
use MonkeysLegion\Entity\Attributes\OneToOne;
use MonkeysLegion\Entity\Attributes\OneToMany;
use MonkeysLegion\Entity\Attributes\ManyToOne;
use MonkeysLegion\Entity\Attributes\ManyToMany;
use MonkeysLegion\Entity\Attributes\JoinTable;

#[Entity]
class Plan
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'decimal', nullable: true)]
    public ?float $monthlyPrice = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $includedMessages = null;
    #[Field(type: 'decimal', nullable: true)]
    public ?float $averagePricePer1K = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $features = null;
    
     /** @var Company[] */
    #[oneToMany(targetEntity: Company::class, mappedBy: 'plan')]
    public array $companies = [];

    public function __construct()
    {
        $this->companies = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): self
    {
        $this->name = $name;
        return $this;
    }

    public function getMonthlyPrice(): ?float
    {
        return $this->monthlyPrice;
    }

    public function setMonthlyPrice(?float $monthlyPrice): self
    {
        $this->monthlyPrice = $monthlyPrice;
        return $this;
    }

    public function getIncludedMessages(): ?int
    {
        return $this->includedMessages;
    }

    public function setIncludedMessages(?int $includedMessages): self
    {
        $this->includedMessages = $includedMessages;
        return $this;
    }

    public function getAveragePricePer1K(): ?float
    {
        return $this->averagePricePer1K;
    }

    public function setAveragePricePer1K(?float $averagePricePer1K): self
    {
        $this->averagePricePer1K = $averagePricePer1K;
        return $this;
    }

    public function getFeatures(): ?array
    {
        return $this->features;
    }

    public function setFeatures(?array $features): self
    {
        $this->features = $features;
        return $this;
    }

    public function addCompany(Company $item): self
    {
        $this->companies[] = $item;
        return $this;
    }

    public function removeCompany(Company $item): self
    {
        $this->companies = array_filter($this->companies, fn($i) => $i !== $item);
        return $this;
    }

    public function getCompanies(): array
    {
        return $this->companies;
    }
}