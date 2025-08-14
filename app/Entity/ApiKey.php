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
class ApiKey
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $label = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $prefix = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $hash = null;
    #[Field(type: 'json', nullable: true)]
    public ?array $scopes = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $last_used_at = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $revoked_at = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    
    #[ManyToOne(targetEntity: Company::class, inversedBy: 'apiKeys')]
    public ?Company $company = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): self
    {
        $this->label = $label;
        return $this;
    }

    public function getPrefix(): ?string
    {
        return $this->prefix;
    }

    public function setPrefix(?string $prefix): self
    {
        $this->prefix = $prefix;
        return $this;
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

    public function getScopes(): ?array
    {
        return $this->scopes;
    }

    public function setScopes(?array $scopes): self
    {
        $this->scopes = $scopes;
        return $this;
    }

    public function getLast_used_at(): ?\DateTimeImmutable
    {
        return $this->last_used_at;
    }

    public function setLast_used_at(?\DateTimeImmutable $last_used_at): self
    {
        $this->last_used_at = $last_used_at;
        return $this;
    }

    public function getRevoked_at(): ?\DateTimeImmutable
    {
        return $this->revoked_at;
    }

    public function setRevoked_at(?\DateTimeImmutable $revoked_at): self
    {
        $this->revoked_at = $revoked_at;
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
}