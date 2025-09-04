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
class Invoice
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'date', nullable: true)]
    public ?\DateTimeImmutable $period_start = null;
    
    #[Field(type: 'date', nullable: true)]
    public ?\DateTimeImmutable $period_end = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $messages_count = null;
    
    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $overage_count = null;

    #[Field(type: 'decimal', nullable: true, default: 0.00)]
    public ?float $subtotal = null;
    
    #[Field(type: 'decimal', nullable: true, default: 0.00)]
    public ?float $tax = null;

    #[Field(type: 'decimal', nullable: true, default: 0.00)]
    public ?float $total = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $status = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $issued_at = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $paid_at = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    
    #[manyToOne(targetEntity: Company::class, inversedBy: 'invoices')]
    public ?Company $company = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPeriod_start(): ?\DateTimeImmutable
    {
        return $this->period_start;
    }

    public function setPeriod_start(?\DateTimeImmutable $period_start): self
    {
        $this->period_start = $period_start;
        return $this;
    }

    public function getPeriod_end(): ?\DateTimeImmutable
    {
        return $this->period_end;
    }

    public function setPeriod_end(?\DateTimeImmutable $period_end): self
    {
        $this->period_end = $period_end;
        return $this;
    }

    public function getMessages_count(): ?int
    {
        return $this->messages_count;
    }

    public function setMessages_count(?int $messages_count): self
    {
        $this->messages_count = $messages_count;
        return $this;
    }

    public function getOverage_count(): ?int
    {
        return $this->overage_count;
    }

    public function setOverage_count(?int $overage_count): self
    {
        $this->overage_count = $overage_count;
        return $this;
    }

    public function getSubtotal(): ?float
    {
        return $this->subtotal;
    }

    public function setSubtotal(?float $subtotal): self
    {
        $this->subtotal = $subtotal;
        return $this;
    }

    public function getTax(): ?float
    {
        return $this->tax;
    }

    public function setTax(?float $tax): self
    {
        $this->tax = $tax;
        return $this;
    }

    public function getTotal(): ?float
    {
        return $this->total;
    }

    public function setTotal(?float $total): self
    {
        $this->total = $total;
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

    public function getIssued_at(): ?\DateTimeImmutable
    {
        return $this->issued_at;
    }

    public function setIssued_at(?\DateTimeImmutable $issued_at): self
    {
        $this->issued_at = $issued_at;
        return $this;
    }

    public function getPaid_at(): ?\DateTimeImmutable
    {
        return $this->paid_at;
    }

    public function setPaid_at(?\DateTimeImmutable $paid_at): self
    {
        $this->paid_at = $paid_at;
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