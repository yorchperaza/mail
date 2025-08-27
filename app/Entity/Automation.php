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
class Automation
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $name = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $trigger = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $flow = null;
    
    #[Field(type: 'string', nullable: true)]
    public ?string $status = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $last_run_at = null;
    
    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;

    #[manyToOne(targetEntity: Company::class, inversedBy: 'automations')]
    public ?Company $company = null;

    public function __construct()
    {
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

    public function getTrigger(): ?string
    {
        return $this->trigger;
    }

    public function setTrigger(?string $trigger): self
    {
        $this->trigger = $trigger;
        return $this;
    }

    public function getFlow(): ?array
    {
        return $this->flow;
    }

    public function setFlow(?array $flow): self
    {
        $this->flow = $flow;
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

    public function getLast_run_at(): ?\DateTimeImmutable
    {
        return $this->last_run_at;
    }

    public function setLast_run_at(?\DateTimeImmutable $last_run_at): self
    {
        $this->last_run_at = $last_run_at;
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