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
class MtaStsPolicy
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $mode = null;
    
    #[Field(type: 'json', nullable: true)]
    public ?array $mx_hosts = null;

    #[Field(type: 'integer', nullable: true)]
    public ?int $max_age = null;
    
    #[Field(type: 'integer', nullable: true)]
    public ?int $version = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $published_at = null;
    
    #[manyToOne(targetEntity: Domain::class, inversedBy: 'mtaStsPolicies')]
    public ?Domain $domain = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getMode(): ?string
    {
        return $this->mode;
    }

    public function setMode(?string $mode): self
    {
        $this->mode = $mode;
        return $this;
    }

    public function getMx_hosts(): ?array
    {
        return $this->mx_hosts;
    }

    public function setMx_hosts(?array $mx_hosts): self
    {
        $this->mx_hosts = $mx_hosts;
        return $this;
    }

    public function getMax_age(): ?int
    {
        return $this->max_age;
    }

    public function setMax_age(?int $max_age): self
    {
        $this->max_age = $max_age;
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

    public function getPublished_at(): ?\DateTimeImmutable
    {
        return $this->published_at;
    }

    public function setPublished_at(?\DateTimeImmutable $published_at): self
    {
        $this->published_at = $published_at;
        return $this;
    }

    public function getDomain(): ?Domain
    {
        return $this->domain;
    }

    public function setDomain(?Domain $domain): self
    {
        $this->domain = $domain;
        return $this;
    }

    public function removeDomain(): self
    {
        $this->domain = null;
        return $this;
    }
}