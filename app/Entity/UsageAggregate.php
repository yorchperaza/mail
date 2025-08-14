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
class UsageAggregate
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $date = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $sent = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $delivered = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $bounced = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $complained = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $opens = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $clicks = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[manyToOne(targetEntity: Company::class, inversedBy: 'usageAggregates')]
    public ?Company $company = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getDate(): ?\DateTimeImmutable
    {
        return $this->date;
    }

    public function setDate(?\DateTimeImmutable $date): self
    {
        $this->date = $date;
        return $this;
    }

    public function getSent(): ?int
    {
        return $this->sent;
    }

    public function setSent(?int $sent): self
    {
        $this->sent = $sent;
        return $this;
    }

    public function getDelivered(): ?int
    {
        return $this->delivered;
    }

    public function setDelivered(?int $delivered): self
    {
        $this->delivered = $delivered;
        return $this;
    }

    public function getBounced(): ?int
    {
        return $this->bounced;
    }

    public function setBounced(?int $bounced): self
    {
        $this->bounced = $bounced;
        return $this;
    }

    public function getComplained(): ?int
    {
        return $this->complained;
    }

    public function setComplained(?int $complained): self
    {
        $this->complained = $complained;
        return $this;
    }

    public function getOpens(): ?int
    {
        return $this->opens;
    }

    public function setOpens(?int $opens): self
    {
        $this->opens = $opens;
        return $this;
    }

    public function getClicks(): ?int
    {
        return $this->clicks;
    }

    public function setClicks(?int $clicks): self
    {
        $this->clicks = $clicks;
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