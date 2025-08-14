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
class BimiRecord
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $svg_ref = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $vmc_ref = null;

    #[Field(type: 'string', nullable: true)]
    public ?string $vmc_issuer = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $vmc_serial = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $validated_at = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $expires_at = null;

    #[manyToOne(targetEntity: Domain::class, inversedBy: 'bimiRecords')]
    public ?Domain $domain = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getSvg_ref(): ?string
    {
        return $this->svg_ref;
    }

    public function setSvg_ref(?string $svg_ref): self
    {
        $this->svg_ref = $svg_ref;
        return $this;
    }

    public function getVmc_ref(): ?string
    {
        return $this->vmc_ref;
    }

    public function setVmc_ref(?string $vmc_ref): self
    {
        $this->vmc_ref = $vmc_ref;
        return $this;
    }

    public function getVmc_issuer(): ?string
    {
        return $this->vmc_issuer;
    }

    public function setVmc_issuer(?string $vmc_issuer): self
    {
        $this->vmc_issuer = $vmc_issuer;
        return $this;
    }

    public function getVmc_serial(): ?string
    {
        return $this->vmc_serial;
    }

    public function setVmc_serial(?string $vmc_serial): self
    {
        $this->vmc_serial = $vmc_serial;
        return $this;
    }

    public function getValidated_at(): ?\DateTimeImmutable
    {
        return $this->validated_at;
    }

    public function setValidated_at(?\DateTimeImmutable $validated_at): self
    {
        $this->validated_at = $validated_at;
        return $this;
    }

    public function getExpires_at(): ?\DateTimeImmutable
    {
        return $this->expires_at;
    }

    public function setExpires_at(?\DateTimeImmutable $expires_at): self
    {
        $this->expires_at = $expires_at;
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