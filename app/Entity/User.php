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
class User
{
    #[Field(type: 'integer')]
    public int $id;

    #[Field(type: 'string', length: 255)]
    public string $email;
    #[Field(type: 'string', length: 255)]
    public string $passwordHash;

    #[Field(type: 'string', nullable: true)]
    public ?string $fullName = null;
    /** @var Company[] */
    #[ManyToMany(targetEntity: Company::class, inversedBy: 'users', joinTable: new JoinTable(name: 'company_user', joinColumn: 'user_id', inverseColumn: 'company_id'))]
    public array $companies = [];

    #[Field(type: 'boolean', nullable: true)]
    public ?bool $status = null;
    #[Field(type: 'boolean', nullable: true)]
    public ?bool $mfaEnabled = null;

    #[Field(type: 'text', nullable: true)]
    public ?string $mfaSecret = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $lastLoginAt = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $createdAt = null;
    /** @var Role[] */
    #[ManyToMany(targetEntity: Role::class, inversedBy: 'users', joinTable: new JoinTable(name: 'role_user', joinColumn: 'user_id', inverseColumn: 'role_id'))]
    public array $roles = [];

    #[OneToOne(targetEntity: Media::class, inversedBy: 'user')]
    public ?Media $media = null;

    public function __construct()
    {
        // any initialization if needed
        $this->companies = [];
        $this->roles = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): string
    {
        return $this->email;
    }

    public function setEmail(string $email): self
    {
        $this->email = $email;
        return $this;
    }

    public function getPasswordHash(): string
    {
        return $this->passwordHash;
    }

    public function setPasswordHash(string $hash): self
    {
        $this->passwordHash = $hash;
        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    public function setFullName(?string $fullName): self
    {
        $this->fullName = $fullName;
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

    public function getStatus(): ?bool
    {
        return $this->status;
    }

    public function setStatus(?bool $status): self {
        $this->status = $status === null ? null : (bool) $status;
        return $this;
    }

    public function getMfaEnabled(): ?bool
    {
        return $this->mfaEnabled;
    }

    public function setMfaEnabled(?bool $mfaEnabled): self
    {
        $this->mfaEnabled = $mfaEnabled;
        return $this;
    }

    public function getMfaSecret(): ?string
    {
        return $this->mfaSecret;
    }

    public function setMfaSecret(?string $mfaSecret): self
    {
        $this->mfaSecret = $mfaSecret;
        return $this;
    }

    public function getLastLoginAt(): ?\DateTimeImmutable
    {
        return $this->lastLoginAt;
    }

    public function setLastLoginAt(?\DateTimeImmutable $lastLoginAt): self
    {
        $this->lastLoginAt = $lastLoginAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setCreatedAt(?\DateTimeImmutable $createdAt): self
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function addRole(Role $item): self
    {
        $this->roles[] = $item;
        return $this;
    }

    public function removeRole(Role $item): self
    {
        $this->roles = array_filter($this->roles, fn($i) => $i !== $item);
        return $this;
    }

    public function getRoles(): array
    {
        return $this->roles;
    }

    public function getMedia(): ?Media
    {
        return $this->media;
    }

    public function setMedia(?Media $media): self
    {
        $this->media = $media;
        return $this;
    }

    public function removeMedia(): self
    {
        $this->media = null;
        return $this;
    }
}