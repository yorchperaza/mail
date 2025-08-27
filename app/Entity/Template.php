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
class Template
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $engine = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $version = null;
    #[Field(type: 'longText', nullable: true)]
    public ?string $html = null;

    #[Field(type: 'longText', nullable: true)]
    public ?string $text = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[ManyToOne(targetEntity: Company::class, inversedBy: 'templates')]
    public ?Company $company = null;
    /** @var Campaign[] */
    #[OneToMany(targetEntity: Campaign::class, mappedBy: 'template')]
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

    public function getEngine(): ?string
    {
        return $this->engine;
    }

    public function setEngine(?string $engine): self
    {
        $this->engine = $engine;
        return $this;
    }

    public function getVersion(): ?int
    {
        return $this->version;
    }

    public function setVersion(?int $version): self
    {
        $this->version = $version;
        return $this;
    }

    public function getHtml(): ?string
    {
        return $this->html;
    }

    public function setHtml(?string $html): self
    {
        $this->html = $html;
        return $this;
    }

    public function getText(): ?string
    {
        return $this->text;
    }

    public function setText(?string $text): self
    {
        $this->text = $text;
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