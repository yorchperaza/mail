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
class Contact
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', length: 320, nullable: true)]
    public ?string $email = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $locale = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $timezone = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $gdpr_consent_at = null;
    #[Field(type: 'enum', nullable: true)]
    public ?string $status = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $attributes = null;
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[manyToOne(targetEntity: Company::class, inversedBy: 'contacts')]
    public ?Company $company = null;
    #[Field(type: 'string', nullable: true)]
    public ?string $consent_source = null;
    
     /** @var ListContact[] */
    #[oneToMany(targetEntity: ListContact::class, mappedBy: 'contact')]
    public array $listContacts = [];

    public function __construct()
    {
        $this->listContacts = [];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): self
    {
        $this->email = $email;
        return $this;
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

    public function getLocale(): ?string
    {
        return $this->locale;
    }

    public function setLocale(?string $locale): self
    {
        $this->locale = $locale;
        return $this;
    }

    public function getTimezone(): ?string
    {
        return $this->timezone;
    }

    public function setTimezone(?string $timezone): self
    {
        $this->timezone = $timezone;
        return $this;
    }

    public function getGdpr_consent_at(): ?\DateTimeImmutable
    {
        return $this->gdpr_consent_at;
    }

    public function setGdpr_consent_at(?\DateTimeImmutable $gdpr_consent_at): self
    {
        $this->gdpr_consent_at = $gdpr_consent_at;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(?string $status): self
    {
        $this->status = $status;
        return $this;
    }

    public function getAttributes(): ?array
    {
        return $this->attributes;
    }

    public function setAttributes(?array $attributes): self
    {
        $this->attributes = $attributes;
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

    public function getConsent_source(): ?string
    {
        return $this->consent_source;
    }

    public function setConsent_source(?string $consent_source): self
    {
        $this->consent_source = $consent_source;
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
}