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
class RateLimitCounter
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $key = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $window_start = null;

    #[Field(type: 'integer', nullable: true, default: 0)]
    public ?int $count = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $updated_at = null;

    #[manyToOne(targetEntity: Company::class, inversedBy: 'rateLimitCounters')]
    public ?Company $company = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getKey(): ?string
    {
        return $this->key;
    }

    public function setKey(?string $key): self
    {
        $this->key = $key;
        return $this;
    }

    public function getWindow_start(): ?\DateTimeImmutable
    {
        return $this->window_start;
    }

    public function setWindow_start(?\DateTimeImmutable $window_start): self
    {
        $this->window_start = $window_start;
        return $this;
    }

    public function getCount(): ?int
    {
        return $this->count;
    }

    public function setCount(?int $count): self
    {
        $this->count = $count;
        return $this;
    }

    public function getUpdated_at(): ?\DateTimeImmutable
    {
        return $this->updated_at;
    }

    public function setUpdated_at(?\DateTimeImmutable $updated_at): self
    {
        $this->updated_at = $updated_at;
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