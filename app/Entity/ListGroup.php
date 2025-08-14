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
class ListGroup
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[manyToOne(targetEntity: Company::class, inversedBy: 'listGroups')]
    public ?Company $company = null;
    /** @var ListContact[] */
    #[oneToMany(targetEntity: ListContact::class, mappedBy: 'listGroup')]
    public array $listContacts = [];
    
     /** @var Campaign[] */
    #[oneToMany(targetEntity: Campaign::class, mappedBy: 'listGroup')]
    public array $campaigns = [];

    public function __construct()
    {
        $this->listContacts = [];
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

    public function getCreated_at(): ?\DateTimeImmutable
    {
        return $this->created_at;
    }

    public function setCreated_at(?\DateTimeImmutable $created_at): self
    {
        $this->created_at = $created_at;
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

    public function addListContact(ListContact $item): self
    {
        $this->listContacts[] = $item;
        return $this;
    }

    public function removeListContact(ListContact $item): self
    {
        $this->listContacts = array_filter($this->listContacts, fn($i) => $i !== $item);
        return $this;
    }

    public function getListContacts(): array
    {
        return $this->listContacts;
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
}