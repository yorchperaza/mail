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
class InboundRoute
{
    #[Field(type: 'INT', autoIncrement: true, primaryKey: true)]
    public int $id;

    #[Field(type: 'string', nullable: true)]
    public ?string $pattern = null;
    
    #[Field(type: 'enum', nullable: true)]
    public ?string $action = null;

    #[Field(type: 'json', nullable: true)]
    public ?array $destination = null;
    
    #[Field(type: 'decimal', nullable: true)]
    public ?float $spam_threshold = null;

    #[Field(type: 'tinyInt', nullable: true, default: 0)]
    public ?int $dkim_required = null;
    
    #[Field(type: 'tinyInt', nullable: true, default: 0)]
    public ?int $tls_required = null;

    #[Field(type: 'datetime', nullable: true)]
    public ?\DateTimeImmutable $created_at = null;
    
    #[manyToOne(targetEntity: Company::class, inversedBy: 'inboundRoutes')]
    public ?Company $company = null;

    #[manyToOne(targetEntity: Domain::class, inversedBy: 'inboundRoutes')]
    public ?Domain $domain = null;

    public function __construct()
    {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPattern(): ?string
    {
        return $this->pattern;
    }

    public function setPattern(?string $pattern): self
    {
        $this->pattern = $pattern;
        return $this;
    }

    public function getAction(): ?string
    {
        return $this->action;
    }

    public function setAction(?string $action): self
    {
        $this->action = $action;
        return $this;
    }

    public function getDestination(): ?array
    {
        return $this->destination;
    }

    public function setDestination(?array $destination): self
    {
        $this->destination = $destination;
        return $this;
    }

    public function getSpam_threshold(): ?float
    {
        return $this->spam_threshold;
    }

    public function setSpam_threshold(?float $spam_threshold): self
    {
        $this->spam_threshold = $spam_threshold;
        return $this;
    }

    public function getDkim_required(): ?int
    {
        return $this->dkim_required;
    }

    public function setDkim_required(?int $dkim_required): self
    {
        $this->dkim_required = $dkim_required;
        return $this;
    }

    public function getTls_required(): ?int
    {
        return $this->tls_required;
    }

    public function setTls_required(?int $tls_required): self
    {
        $this->tls_required = $tls_required;
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