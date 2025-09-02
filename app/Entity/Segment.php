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
class Segment
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $definition = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $materialized_count = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $last_built_at = null;

    #[ManyToOne(targetEntity: Company::class, inversedBy: 'segments')]
    public ?Company $company = null;
    /** @var Campaign[] */
    #[OneToMany(targetEntity: Campaign::class, mappedBy: 'segment')]
    public array $campaigns = [];

    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;

    public function __construct()
    {
        $this->campaigns = [];
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

    public function getDefinition(): ?array
    {
        return $this->definition;
    }

    public function setDefinition(?array $definition): self
    {
        $this->definition = $definition;
        return $this;
    }

    public function getMaterialized_count(): ?int
    {
        return $this->materialized_count;
    }

    public function setMaterialized_count(?int $materialized_count): self
    {
        $this->materialized_count = $materialized_count;
        return $this;
    }

    public function getLast_built_at(): ?\DateTimeImmutable
    {
        return $this->last_built_at;
    }

    public function setLast_built_at(?\DateTimeImmutable $last_built_at): self
    {
        $this->last_built_at = $last_built_at;
        return $this;
    }

    public function getCompany(): ?Company
    {
        return $this->company;
    }

    public function setCompany(?Company $company): self
    {
        $this->company = $company;
        return $this;
    }

    public function removeCompany(): self
    {
        $this->company = null;
        return $this;
    }

    public function addCampaign(Campaign $item): self
    {
        $this->campaigns[] = $item;
        return $this;
    }

    public function removeCampaign(Campaign $item): self
    {
        $this->campaigns = array_filter($this->campaigns, fn($i) => $i !== $item);
        return $this;
    }

    public function getCampaigns(): array
    {
        return $this->campaigns;
    }

    public function getHash(): ?string
    {
        return $this->hash;
    }

    public function setHash(?string $hash): self
    {
        $this->hash = $hash;
        return $this;
    }
}